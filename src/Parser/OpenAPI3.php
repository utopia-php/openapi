<?php

declare(strict_types=1);

namespace Utopia\OpenAPI\Parser;

use Utopia\OpenAPI\Exception\InvalidSpecification;
use Utopia\OpenAPI\Model\HttpMethod;
use Utopia\OpenAPI\Model\Operation;
use Utopia\OpenAPI\Model\Parameter;
use Utopia\OpenAPI\Model\ParameterLocation;
use Utopia\OpenAPI\Model\PathItem;
use Utopia\OpenAPI\Model\RequestBody;
use Utopia\OpenAPI\Model\Response;
use Utopia\OpenAPI\Specification;

abstract class OpenAPI3 extends AbstractReader
{
    public function read(): Specification
    {
        $security = $this->parseSecurity($this->document['security'] ?? [], '#/security');
        $servers = $this->parseServers($this->document['servers'] ?? [], '#/servers');
        $components = $this->object($this->document['components'] ?? [], '#/components');

        $schemas = [];
        foreach ($this->object($components['schemas'] ?? [], '#/components/schemas') as $name => $raw) {
            $schemas[(string) $name] = $this->parseSchema($raw, "#/components/schemas/{$name}");
        }

        $securitySchemes = [];
        foreach ($this->object($components['securitySchemes'] ?? [], '#/components/securitySchemes') as $name => $raw) {
            $data = $this->resolveObject($raw, "#/components/securitySchemes/{$name}");
            $securitySchemes[(string) $name] = $this->parseSecurityScheme($data, "#/components/securitySchemes/{$name}");
        }

        return new Specification(
            version: $this->version,
            info: $this->parseInfo(),
            servers: $servers,
            tags: $this->parseTags(),
            paths: $this->parsePaths($security, $servers),
            schemas: $schemas,
            securitySchemes: $securitySchemes,
            security: $security,
            extensions: $this->extensions($this->document),
            sourceVersion: $this->sourceVersion,
            jsonSchemaDialect: $this->optionalString($this->document, 'jsonSchemaDialect'),
            externalDocumentation: isset($this->document['externalDocs'])
                ? $this->parseExternalDocumentation($this->document['externalDocs'], '#/externalDocs')
                : null,
        );
    }

    /** @return array<string, PathItem> */
    private function parsePaths(array $rootSecurity, array $rootServers): array
    {
        $paths = [];
        foreach ($this->object($this->document['paths'] ?? [], '#/paths') as $path => $raw) {
            $location = '#/paths/' . str_replace(['~', '/'], ['~0', '~1'], (string) $path);
            $data = $this->resolveObject($raw, $location);
            $pathParameters = $this->parseParameters($data['parameters'] ?? [], "{$location}/parameters");
            $pathServers = array_key_exists('servers', $data)
                ? $this->parseServers($data['servers'], "{$location}/servers")
                : $rootServers;
            $operations = [];
            foreach (HttpMethod::cases() as $method) {
                if (!array_key_exists($method->value, $data)) {
                    continue;
                }
                $operations[$method->value] = $this->parseOperation(
                    (string) $path,
                    $method,
                    $data[$method->value],
                    $pathParameters,
                    $rootSecurity,
                    $pathServers,
                    "{$location}/{$method->value}",
                );
            }
            $paths[(string) $path] = new PathItem(
                path: (string) $path,
                operations: $operations,
                parameters: $pathParameters,
                summary: $this->optionalString($data, 'summary') ?? '',
                description: $this->optionalString($data, 'description') ?? '',
                servers: $pathServers,
                extensions: $this->extensions($data),
            );
        }
        return $paths;
    }

    private function parseOperation(
        string $path,
        HttpMethod $method,
        mixed $raw,
        array $pathParameters,
        array $rootSecurity,
        array $inheritedServers,
        string $location,
    ): Operation {
        $data = $this->object($raw, $location);
        $operationParameters = $this->parseParameters($data['parameters'] ?? [], "{$location}/parameters");
        $parameters = $this->mergeParameters($pathParameters, $operationParameters);
        $requestBody = null;
        if (array_key_exists('requestBody', $data)) {
            $value = $this->resolveObject($data['requestBody'], "{$location}/requestBody");
            $requestBody = new RequestBody(
                description: $this->optionalString($value, 'description') ?? '',
                required: (bool) ($value['required'] ?? false),
                content: $this->parseMediaTypes($value['content'] ?? [], "{$location}/requestBody/content"),
                extensions: $this->extensions($value),
            );
        }

        $responses = [];
        foreach ($this->object($data['responses'] ?? null, "{$location}/responses") as $status => $rawResponse) {
            $value = $this->resolveObject($rawResponse, "{$location}/responses/{$status}");
            $responses[(string) $status] = new Response(
                description: $this->requiredString($value, 'description', "{$location}/responses/{$status}"),
                headers: $this->parseHeaders($value['headers'] ?? [], "{$location}/responses/{$status}/headers"),
                content: $this->parseMediaTypes($value['content'] ?? [], "{$location}/responses/{$status}/content"),
                extensions: $this->extensions($value),
            );
        }

        $tags = isset($data['tags'])
            ? array_map('strval', $this->list($data['tags'], "{$location}/tags"))
            : [];
        $security = array_key_exists('security', $data)
            ? $this->parseSecurity($data['security'], "{$location}/security")
            : $rootSecurity;
        $servers = array_key_exists('servers', $data)
            ? $this->parseServers($data['servers'], "{$location}/servers")
            : $inheritedServers;

        return new Operation(
            id: $this->optionalString($data, 'operationId') ?? '',
            method: $method,
            path: $path,
            tags: $tags,
            summary: $this->optionalString($data, 'summary') ?? '',
            description: $this->optionalString($data, 'description') ?? '',
            deprecated: (bool) ($data['deprecated'] ?? false),
            parameters: $parameters,
            requestBody: $requestBody,
            responses: $responses,
            security: $security,
            servers: $servers,
            externalDocumentation: isset($data['externalDocs'])
                ? $this->parseExternalDocumentation($data['externalDocs'], "{$location}/externalDocs")
                : null,
            extensions: $this->extensions($data),
        );
    }

    /** @return list<Parameter> */
    private function parseParameters(mixed $raw, string $location): array
    {
        $parameters = [];
        foreach ($this->list($raw, $location) as $index => $item) {
            $data = $this->resolveObject($item, "{$location}/{$index}");
            $locationValue = $this->requiredString($data, 'in', "{$location}/{$index}");
            try {
                $parameterLocation = ParameterLocation::from($locationValue);
            } catch (\ValueError) {
                throw new InvalidSpecification("Unsupported parameter location '{$locationValue}' at {$location}/{$index}/in");
            }
            $required = (bool) ($data['required'] ?? false);
            if ($parameterLocation === ParameterLocation::PATH && !$required) {
                throw new InvalidSpecification("Path parameter must be required at {$location}/{$index}");
            }
            $parameters[] = new Parameter(
                name: $this->requiredString($data, 'name', "{$location}/{$index}"),
                location: $parameterLocation,
                description: $this->optionalString($data, 'description') ?? '',
                required: $required,
                deprecated: (bool) ($data['deprecated'] ?? false),
                allowEmptyValue: (bool) ($data['allowEmptyValue'] ?? false),
                schema: array_key_exists('schema', $data) ? $this->parseSchema($data['schema'], "{$location}/{$index}/schema") : null,
                content: isset($data['content']) ? $this->parseMediaTypes($data['content'], "{$location}/{$index}/content") : [],
                style: $this->optionalString($data, 'style'),
                explode: array_key_exists('explode', $data) ? (bool) $data['explode'] : null,
                allowReserved: (bool) ($data['allowReserved'] ?? false),
                extensions: $this->extensions($data),
            );
        }
        return $parameters;
    }

    /** @param list<Parameter> $inherited @param list<Parameter> $operation @return list<Parameter> */
    private function mergeParameters(array $inherited, array $operation): array
    {
        $merged = $inherited;
        $indexes = [];
        foreach ($merged as $index => $parameter) {
            $indexes[$parameter->identity()] = $index;
        }
        foreach ($operation as $parameter) {
            $identity = $parameter->identity();
            if (array_key_exists($identity, $indexes)) {
                $merged[$indexes[$identity]] = $parameter;
            } else {
                $indexes[$identity] = count($merged);
                $merged[] = $parameter;
            }
        }
        return array_values($merged);
    }
}
