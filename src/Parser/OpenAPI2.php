<?php

declare(strict_types=1);

namespace Utopia\OpenAPI\Parser;

use Utopia\OpenAPI\Exception\InvalidSpecification;
use Utopia\OpenAPI\Model\Encoding;
use Utopia\OpenAPI\Model\HttpMethod;
use Utopia\OpenAPI\Model\MediaType;
use Utopia\OpenAPI\Model\Operation;
use Utopia\OpenAPI\Model\Parameter;
use Utopia\OpenAPI\Model\ParameterLocation;
use Utopia\OpenAPI\Model\PathItem;
use Utopia\OpenAPI\Model\RequestBody;
use Utopia\OpenAPI\Model\Response;
use Utopia\OpenAPI\Model\Schema\ObjectSchema;
use Utopia\OpenAPI\Model\Server;
use Utopia\OpenAPI\Specification;

final class OpenAPI2 extends AbstractReader
{
    public function read(): Specification
    {
        $security = $this->parseSecurity($this->document['security'] ?? [], '#/security');
        $consumes = $this->mediaNames($this->document['consumes'] ?? [], '#/consumes');
        $produces = $this->mediaNames($this->document['produces'] ?? [], '#/produces');

        $schemas = [];
        foreach ($this->object($this->document['definitions'] ?? [], '#/definitions') as $name => $raw) {
            $schemas[(string) $name] = $this->parseSchema($raw, "#/definitions/{$name}");
        }

        $securitySchemes = [];
        foreach ($this->object($this->document['securityDefinitions'] ?? [], '#/securityDefinitions') as $name => $raw) {
            $data = $this->resolveObject($raw, "#/securityDefinitions/{$name}");
            $securitySchemes[(string) $name] = $this->parseSecurityScheme($data, "#/securityDefinitions/{$name}", true);
        }

        return new Specification(
            version: $this->version,
            info: $this->parseInfo(),
            servers: $this->parseOpenApi2Servers(),
            tags: $this->parseTags(),
            paths: $this->parsePaths($security, $consumes, $produces),
            schemas: $schemas,
            securitySchemes: $securitySchemes,
            security: $security,
            extensions: $this->extensions($this->document),
            sourceVersion: $this->sourceVersion,
            externalDocumentation: isset($this->document['externalDocs'])
                ? $this->parseExternalDocumentation($this->document['externalDocs'], '#/externalDocs')
                : null,
        );
    }

    /** @return list<Server> */
    private function parseOpenApi2Servers(): array
    {
        $host = $this->optionalString($this->document, 'host');
        $basePath = $this->optionalString($this->document, 'basePath') ?? '';
        $schemes = $this->mediaNames($this->document['schemes'] ?? [], '#/schemes');
        if ($host === null) {
            return [];
        }
        if ($schemes === []) {
            $schemes = ['http'];
        }
        return array_map(
            static fn (string $scheme): Server => new Server(rtrim($scheme . '://' . $host, '/') . ($basePath === '/' ? '' : $basePath)),
            $schemes,
        );
    }

    /** @return array<string, PathItem> */
    private function parsePaths(array $rootSecurity, array $rootConsumes, array $rootProduces): array
    {
        $paths = [];
        foreach ($this->object($this->document['paths'] ?? [], '#/paths') as $path => $raw) {
            $location = '#/paths/' . str_replace(['~', '/'], ['~0', '~1'], (string) $path);
            $data = $this->resolveObject($raw, $location);
            $pathParameters = $this->rawParameters($data['parameters'] ?? [], "{$location}/parameters");
            $operations = [];
            foreach (HttpMethod::cases() as $method) {
                if (!array_key_exists($method->value, $data)) {
                    continue;
                }
                $operations[$method->value] = $this->parseOperation((string) $path, $method, $data[$method->value], $pathParameters, $rootSecurity, $rootConsumes, $rootProduces, "{$location}/{$method->value}");
            }
            $parsedPathParameters = [];
            foreach ($pathParameters as $index => $parameter) {
                if (($parameter['in'] ?? null) !== 'body' && ($parameter['in'] ?? null) !== 'formData') {
                    $parsedPathParameters[] = $this->parseParameter($parameter, "{$location}/parameters/{$index}");
                }
            }
            $paths[(string) $path] = new PathItem((string) $path, $operations, $parsedPathParameters, extensions: $this->extensions($data));
        }
        return $paths;
    }

    private function parseOperation(string $path, HttpMethod $method, mixed $raw, array $pathParameters, array $rootSecurity, array $rootConsumes, array $rootProduces, string $location): Operation
    {
        $data = $this->object($raw, $location);
        $operationParameters = $this->rawParameters($data['parameters'] ?? [], "{$location}/parameters");
        $rawParameters = $this->mergeRawParameters($pathParameters, $operationParameters);
        $consumes = array_key_exists('consumes', $data) ? $this->mediaNames($data['consumes'], "{$location}/consumes") : $rootConsumes;
        $produces = array_key_exists('produces', $data) ? $this->mediaNames($data['produces'], "{$location}/produces") : $rootProduces;

        $parameters = [];
        $body = null;
        $form = [];
        foreach ($rawParameters as $index => $parameter) {
            $in = $parameter['in'] ?? null;
            if ($in === 'body') {
                if ($body !== null) {
                    throw new InvalidSpecification("Multiple body parameters at {$location}");
                }
                $schema = array_key_exists('schema', $parameter) ? $this->parseSchema($parameter['schema'], "{$location}/parameters/{$index}/schema") : null;
                $content = [];
                foreach ($consumes as $mediaName) {
                    $content[$mediaName] = new MediaType($schema);
                }
                $body = new RequestBody(
                    description: $this->optionalString($parameter, 'description') ?? '',
                    required: (bool) ($parameter['required'] ?? false),
                    content: $content,
                    extensions: $this->extensions($parameter),
                );
            } elseif ($in === 'formData') {
                $form[] = $parameter;
            } else {
                $parameters[] = $this->parseParameter($parameter, "{$location}/parameters/{$index}");
            }
        }
        if ($body !== null && $form !== []) {
            throw new InvalidSpecification("Body and formData parameters cannot coexist at {$location}");
        }
        if ($form !== []) {
            $body = $this->parseFormBody($form, $consumes, $location);
        }

        $responses = [];
        foreach ($this->object($data['responses'] ?? null, "{$location}/responses") as $status => $rawResponse) {
            $value = $this->resolveObject($rawResponse, "{$location}/responses/{$status}");
            $content = [];
            $examplesByMedia = $this->openApi2Examples($value['examples'] ?? [], "{$location}/responses/{$status}/examples");
            $schema = array_key_exists('schema', $value)
                ? $this->parseSchema($value['schema'], "{$location}/responses/{$status}/schema")
                : null;
            foreach (array_values(array_unique([...$produces, ...array_keys($examplesByMedia)])) as $mediaName) {
                $content[$mediaName] = new MediaType(
                    schema: $schema,
                    example: $examplesByMedia[$mediaName]->value ?? null,
                );
            }
            $responses[(string) $status] = new Response(
                description: $this->requiredString($value, 'description', "{$location}/responses/{$status}"),
                headers: $this->parseHeaders($value['headers'] ?? [], "{$location}/responses/{$status}/headers", true),
                content: $content,
                extensions: $this->extensions($value),
            );
        }

        return new Operation(
            id: $this->optionalString($data, 'operationId') ?? '',
            method: $method,
            path: $path,
            tags: isset($data['tags']) ? array_map('strval', $this->list($data['tags'], "{$location}/tags")) : [],
            summary: $this->optionalString($data, 'summary') ?? '',
            description: $this->optionalString($data, 'description') ?? '',
            deprecated: (bool) ($data['deprecated'] ?? false),
            parameters: $parameters,
            requestBody: $body,
            responses: $responses,
            security: array_key_exists('security', $data) ? $this->parseSecurity($data['security'], "{$location}/security") : $rootSecurity,
            externalDocumentation: isset($data['externalDocs']) ? $this->parseExternalDocumentation($data['externalDocs'], "{$location}/externalDocs") : null,
            extensions: $this->extensions($data),
        );
    }

    /** @return list<array<string, mixed>> */
    private function rawParameters(mixed $raw, string $location): array
    {
        $parameters = [];
        foreach ($this->list($raw, $location) as $index => $item) {
            $parameters[] = $this->resolveObject($item, "{$location}/{$index}");
        }
        return $parameters;
    }

    private function parseParameter(array $data, string $location): Parameter
    {
        $locationValue = $this->requiredString($data, 'in', $location);
        try {
            $parameterLocation = ParameterLocation::from($locationValue);
        } catch (\ValueError) {
            throw new InvalidSpecification("Unsupported parameter location '{$locationValue}' at {$location}/in");
        }
        $required = (bool) ($data['required'] ?? false);
        if ($parameterLocation === ParameterLocation::PATH && !$required) {
            throw new InvalidSpecification("Path parameter must be required at {$location}");
        }
        return new Parameter(
            name: $this->requiredString($data, 'name', $location),
            location: $parameterLocation,
            description: $this->optionalString($data, 'description') ?? '',
            required: $required,
            allowEmptyValue: (bool) ($data['allowEmptyValue'] ?? false),
            schema: $this->parseSchema($this->schemaFieldsFromParameter($data), $location),
            extensions: $this->extensions($data),
        );
    }

    private function parseFormBody(array $form, array $consumes, string $location): RequestBody
    {
        $properties = [];
        $required = [];
        $encoding = [];
        foreach ($form as $index => $parameter) {
            $name = $this->requiredString($parameter, 'name', "{$location}/parameters/{$index}");
            $properties[$name] = $this->parseSchema($this->schemaFieldsFromParameter($parameter), "{$location}/parameters/{$index}");
            if ((bool) ($parameter['required'] ?? false)) {
                $required[] = $name;
            }
            $encoding[$name] = new Encoding(
                contentType: ($parameter['type'] ?? null) === 'file' ? 'application/octet-stream' : null,
                extensions: $this->extensions($parameter),
            );
        }
        $schema = new ObjectSchema($properties, $required);
        if ($consumes === []) {
            $consumes = ['application/x-www-form-urlencoded'];
        }
        $content = [];
        foreach ($consumes as $mediaName) {
            $content[$mediaName] = new MediaType($schema, encoding: $encoding);
        }
        return new RequestBody(required: $required !== [], content: $content);
    }

    /** @return array<string, \Utopia\OpenAPI\Model\Example> */
    private function openApi2Examples(mixed $raw, string $location): array
    {
        $examples = [];
        foreach ($this->object($raw, $location) as $mediaName => $value) {
            $examples[(string) $mediaName] = new \Utopia\OpenAPI\Model\Example(value: $value);
        }
        return $examples;
    }

    private function mergeRawParameters(array $inherited, array $operation): array
    {
        $merged = $inherited;
        $indexes = [];
        foreach ($merged as $index => $parameter) {
            $indexes[$this->rawParameterIdentity($parameter)] = $index;
        }
        foreach ($operation as $parameter) {
            $identity = $this->rawParameterIdentity($parameter);
            if (array_key_exists($identity, $indexes)) {
                $merged[$indexes[$identity]] = $parameter;
            } else {
                $indexes[$identity] = count($merged);
                $merged[] = $parameter;
            }
        }
        return array_values($merged);
    }

    private function rawParameterIdentity(array $parameter): string
    {
        return (string) ($parameter['in'] ?? '') . "\0" . (string) ($parameter['name'] ?? '');
    }

    /** @return list<string> */
    private function mediaNames(mixed $raw, string $location): array
    {
        return array_map('strval', $this->list($raw, $location));
    }
}
