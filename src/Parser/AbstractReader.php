<?php

declare(strict_types=1);

namespace Utopia\OpenAPI\Parser;

use Utopia\OpenAPI\Exception\InvalidSpecification;
use Utopia\OpenAPI\Model\Contact;
use Utopia\OpenAPI\Model\Encoding;
use Utopia\OpenAPI\Model\Example;
use Utopia\OpenAPI\Model\ExternalDocumentation;
use Utopia\OpenAPI\Model\Header;
use Utopia\OpenAPI\Model\Info;
use Utopia\OpenAPI\Model\License;
use Utopia\OpenAPI\Model\MediaType;
use Utopia\OpenAPI\Model\OAuthFlow;
use Utopia\OpenAPI\Model\ParameterLocation;
use Utopia\OpenAPI\Model\SecurityRequirement;
use Utopia\OpenAPI\Model\SecurityScheme;
use Utopia\OpenAPI\Model\SecuritySchemeType;
use Utopia\OpenAPI\Model\Server;
use Utopia\OpenAPI\Model\ServerVariable;
use Utopia\OpenAPI\Model\Tag;
use Utopia\OpenAPI\Model\Schema\AnySchema;
use Utopia\OpenAPI\Model\Schema\ArraySchema;
use Utopia\OpenAPI\Model\Schema\BooleanSchema;
use Utopia\OpenAPI\Model\Schema\CompositeSchema;
use Utopia\OpenAPI\Model\Schema\Composition;
use Utopia\OpenAPI\Model\Schema\Discriminator;
use Utopia\OpenAPI\Model\Schema\IntegerSchema;
use Utopia\OpenAPI\Model\Schema\NeverSchema;
use Utopia\OpenAPI\Model\Schema\NumberSchema;
use Utopia\OpenAPI\Model\Schema\ObjectSchema;
use Utopia\OpenAPI\Model\Schema\ReferenceSchema;
use Utopia\OpenAPI\Model\Schema\Schema;
use Utopia\OpenAPI\Model\Schema\StringSchema;
use Utopia\OpenAPI\Reference\LocalResolver;
use Utopia\OpenAPI\Version;

abstract class AbstractReader implements Reader
{
    protected LocalResolver $resolver;

    /** @param array<string, mixed> $document */
    public function __construct(
        protected readonly array $document,
        protected readonly Version $version,
        protected readonly string $sourceVersion,
    ) {
        $this->resolver = new LocalResolver($document);
    }

    /** @return array<string, mixed> */
    protected function object(mixed $value, string $location): array
    {
        if ($value instanceof \stdClass) {
            return (array) $value;
        }
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new InvalidSpecification("Expected an object at {$location}");
        }

        return $value;
    }

    /** @return list<mixed> */
    protected function list(mixed $value, string $location): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidSpecification("Expected a list at {$location}");
        }

        return $value;
    }

    protected function requiredString(array $data, string $key, string $location): string
    {
        if (!array_key_exists($key, $data) || !is_string($data[$key])) {
            throw new InvalidSpecification("Expected string {$location}/{$key}");
        }

        return $data[$key];
    }

    protected function optionalString(array $data, string $key): ?string
    {
        if (!array_key_exists($key, $data) || $data[$key] === null) {
            return null;
        }
        if (!is_string($data[$key])) {
            throw new InvalidSpecification("Expected '{$key}' to be a string");
        }

        return $data[$key];
    }

    /** @return array<string, mixed> */
    protected function extensions(array $data): array
    {
        return array_filter(
            $data,
            static fn (string|int $key): bool => is_string($key) && str_starts_with(strtolower($key), 'x-'),
            ARRAY_FILTER_USE_KEY,
        );
    }

    protected function parseInfo(): Info
    {
        $data = $this->object($this->document['info'] ?? null, '#/info');
        $contact = null;
        if (isset($data['contact'])) {
            $value = $this->object($data['contact'], '#/info/contact');
            $contact = new Contact(
                name: $this->optionalString($value, 'name') ?? '',
                url: $this->optionalString($value, 'url'),
                email: $this->optionalString($value, 'email'),
                extensions: $this->extensions($value),
            );
        }

        $license = null;
        if (isset($data['license'])) {
            $value = $this->object($data['license'], '#/info/license');
            $license = new License(
                name: $this->requiredString($value, 'name', '#/info/license'),
                url: $this->optionalString($value, 'url'),
                identifier: $this->optionalString($value, 'identifier'),
                extensions: $this->extensions($value),
            );
        }

        return new Info(
            title: $this->requiredString($data, 'title', '#/info'),
            description: $this->optionalString($data, 'description') ?? '',
            version: $this->requiredString($data, 'version', '#/info'),
            termsOfService: $this->optionalString($data, 'termsOfService'),
            contact: $contact,
            license: $license,
            extensions: $this->extensions($data),
        );
    }

    /** @return array<string, Tag> */
    protected function parseTags(): array
    {
        $tags = [];
        foreach ($this->list($this->document['tags'] ?? [], '#/tags') as $index => $raw) {
            $data = $this->object($raw, "#/tags/{$index}");
            $name = $this->requiredString($data, 'name', "#/tags/{$index}");
            $tags[$name] = new Tag(
                name: $name,
                description: $this->optionalString($data, 'description') ?? '',
                externalDocumentation: isset($data['externalDocs'])
                    ? $this->parseExternalDocumentation($data['externalDocs'], "#/tags/{$index}/externalDocs")
                    : null,
                extensions: $this->extensions($data),
            );
        }

        return $tags;
    }

    protected function parseExternalDocumentation(mixed $raw, string $location): ExternalDocumentation
    {
        $data = $this->object($raw, $location);
        return new ExternalDocumentation(
            url: $this->requiredString($data, 'url', $location),
            description: $this->optionalString($data, 'description') ?? '',
            extensions: $this->extensions($data),
        );
    }

    /** @return list<Server> */
    protected function parseServers(mixed $raw, string $location): array
    {
        $servers = [];
        foreach ($this->list($raw, $location) as $index => $item) {
            $data = $this->object($item, "{$location}/{$index}");
            $variables = [];
            foreach ($this->object($data['variables'] ?? [], "{$location}/{$index}/variables") as $name => $variable) {
                $value = $this->object($variable, "{$location}/{$index}/variables/{$name}");
                $enum = isset($value['enum']) ? $this->list($value['enum'], "{$location}/{$index}/variables/{$name}/enum") : [];
                $variables[(string) $name] = new ServerVariable(
                    default: (string) ($value['default'] ?? ''),
                    enum: array_map('strval', $enum),
                    description: $this->optionalString($value, 'description') ?? '',
                    extensions: $this->extensions($value),
                );
            }
            $servers[] = new Server(
                url: $this->requiredString($data, 'url', "{$location}/{$index}"),
                description: $this->optionalString($data, 'description') ?? '',
                variables: $variables,
                extensions: $this->extensions($data),
            );
        }

        return $servers;
    }

    /** @return list<SecurityRequirement> */
    protected function parseSecurity(mixed $raw, string $location): array
    {
        $requirements = [];
        foreach ($this->list($raw, $location) as $index => $item) {
            $data = $this->object($item, "{$location}/{$index}");
            $schemes = [];
            foreach ($data as $name => $scopes) {
                $schemes[(string) $name] = array_map(
                    static fn (mixed $scope): string => (string) $scope,
                    $this->list($scopes, "{$location}/{$index}/{$name}"),
                );
            }
            $requirements[] = new SecurityRequirement($schemes);
        }

        return $requirements;
    }

    protected function parseSchema(mixed $raw, string $location): Schema
    {
        if (is_bool($raw)) {
            if ($this->version !== Version::V3_1) {
                throw new InvalidSpecification("Boolean schemas are only supported by OpenAPI 3.1 at {$location}");
            }
            return $raw ? new AnySchema() : new NeverSchema();
        }

        $data = $this->object($raw, $location);
        $common = $this->schemaCommon($data);

        if (isset($data['$ref'])) {
            return new ReferenceSchema(...[
                'reference' => $this->requiredString($data, '$ref', $location),
                ...$common,
            ]);
        }

        $nullable = $common['nullable'];
        $type = $data['type'] ?? null;
        if (is_array($type)) {
            if ($this->version !== Version::V3_1 || !array_is_list($type)) {
                throw new InvalidSpecification("Invalid schema type at {$location}/type");
            }
            $types = array_values(array_filter($type, static fn (mixed $item): bool => $item !== 'null'));
            $nullable = count($types) !== count($type);
            $common['nullable'] = $nullable;
            if (count($types) > 1) {
                $schemas = [];
                foreach ($types as $index => $memberType) {
                    if (!is_string($memberType)) {
                        throw new InvalidSpecification("Invalid schema type at {$location}/type/{$index}");
                    }
                    $schemas[] = $this->parseSchema(['type' => $memberType], "{$location}/type/{$index}");
                }
                return new CompositeSchema(Composition::ANY_OF, $schemas, null, $this->parseDiscriminator($data), ...$common);
            }
            $type = $types[0] ?? null;
        }
        if ($type !== null && !is_string($type)) {
            throw new InvalidSpecification("Invalid schema type at {$location}/type");
        }

        foreach ([Composition::ONE_OF, Composition::ANY_OF, Composition::ALL_OF] as $composition) {
            if (array_key_exists($composition->value, $data)) {
                $schemas = [];
                foreach ($this->list($data[$composition->value], "{$location}/{$composition->value}") as $index => $schema) {
                    $schemas[] = $this->parseSchema($schema, "{$location}/{$composition->value}/{$index}");
                }
                $not = array_key_exists('not', $data) ? $this->parseSchema($data['not'], "{$location}/not") : null;
                return new CompositeSchema($composition, $schemas, $not, $this->parseDiscriminator($data), ...$common);
            }
        }
        if (array_key_exists('not', $data)) {
            return new CompositeSchema(null, [], $this->parseSchema($data['not'], "{$location}/not"), $this->parseDiscriminator($data), ...$common);
        }

        if ($type === null) {
            if (isset($data['properties']) || array_key_exists('additionalProperties', $data)) {
                $type = 'object';
            } elseif (isset($data['items'])) {
                $type = 'array';
            }
        }

        return match ($type) {
            'string', 'file' => new StringSchema(
                minLength: $this->nullableInt($data['minLength'] ?? null, "{$location}/minLength"),
                maxLength: $this->nullableInt($data['maxLength'] ?? null, "{$location}/maxLength"),
                pattern: $this->optionalString($data, 'pattern'),
                format: $type === 'file' ? 'binary' : $common['format'],
                title: $common['title'], description: $common['description'], nullable: $nullable,
                default: $common['default'], enum: $common['enum'], readOnly: $common['readOnly'],
                writeOnly: $common['writeOnly'], deprecated: $common['deprecated'], example: $common['example'],
                extensions: $common['extensions'],
            ),
            'integer' => $this->parseIntegerSchema($data, $location, $common),
            'number' => $this->parseNumberSchema($data, $location, $common),
            'boolean' => new BooleanSchema(...$common),
            'array' => new ArraySchema(
                ...[
                    'items' => array_key_exists('items', $data) ? $this->parseSchema($data['items'], "{$location}/items") : new AnySchema(),
                    'minItems' => $this->nullableInt($data['minItems'] ?? null, "{$location}/minItems"),
                    'maxItems' => $this->nullableInt($data['maxItems'] ?? null, "{$location}/maxItems"),
                    'uniqueItems' => (bool) ($data['uniqueItems'] ?? false),
                    ...$common,
                ],
            ),
            'object' => $this->parseObjectSchema($data, $location, $common),
            'null' => new AnySchema(...['nullable' => true, ...array_diff_key($common, ['nullable' => true])]),
            null => new AnySchema(...$common),
            default => throw new InvalidSpecification("Unsupported schema type '{$type}' at {$location}"),
        };
    }

    /** @return array<string, mixed> */
    private function schemaCommon(array $data): array
    {
        $enum = isset($data['enum']) ? $this->list($data['enum'], 'schema/enum') : [];
        if ($this->version === Version::V3_1 && array_key_exists('const', $data) && !isset($data['enum'])) {
            $enum = [$data['const']];
        }

        return [
            'title' => $this->optionalString($data, 'title'),
            'description' => $this->optionalString($data, 'description') ?? '',
            'nullable' => (bool) ($data['nullable'] ?? $data['x-nullable'] ?? false),
            'default' => $data['default'] ?? null,
            'enum' => $enum,
            'format' => $this->optionalString($data, 'format'),
            'readOnly' => (bool) ($data['readOnly'] ?? false),
            'writeOnly' => (bool) ($data['writeOnly'] ?? false),
            'deprecated' => (bool) ($data['deprecated'] ?? false),
            'example' => $data['example'] ?? null,
            'extensions' => $this->extensions($data),
        ];
    }

    private function parseIntegerSchema(array $data, string $location, array $common): IntegerSchema
    {
        [$minimum, $maximum, $exclusiveMinimum, $exclusiveMaximum] = $this->numericBounds($data, $location);
        return new IntegerSchema($minimum, $maximum, $exclusiveMinimum, $exclusiveMaximum, $this->nullableNumber($data['multipleOf'] ?? null, "{$location}/multipleOf"), ...$common);
    }

    private function parseNumberSchema(array $data, string $location, array $common): NumberSchema
    {
        [$minimum, $maximum, $exclusiveMinimum, $exclusiveMaximum] = $this->numericBounds($data, $location);
        return new NumberSchema($minimum, $maximum, $exclusiveMinimum, $exclusiveMaximum, $this->nullableNumber($data['multipleOf'] ?? null, "{$location}/multipleOf"), ...$common);
    }

    /** @return array{int|float|null, int|float|null, bool, bool} */
    private function numericBounds(array $data, string $location): array
    {
        $minimum = $this->nullableNumber($data['minimum'] ?? null, "{$location}/minimum");
        $maximum = $this->nullableNumber($data['maximum'] ?? null, "{$location}/maximum");
        $exclusiveMinimum = $data['exclusiveMinimum'] ?? false;
        $exclusiveMaximum = $data['exclusiveMaximum'] ?? false;
        if (is_int($exclusiveMinimum) || is_float($exclusiveMinimum)) {
            $minimum = $exclusiveMinimum;
            $exclusiveMinimum = true;
        }
        if (is_int($exclusiveMaximum) || is_float($exclusiveMaximum)) {
            $maximum = $exclusiveMaximum;
            $exclusiveMaximum = true;
        }
        return [$minimum, $maximum, (bool) $exclusiveMinimum, (bool) $exclusiveMaximum];
    }

    private function parseObjectSchema(array $data, string $location, array $common): ObjectSchema
    {
        $properties = [];
        foreach ($this->object($data['properties'] ?? [], "{$location}/properties") as $name => $schema) {
            $properties[(string) $name] = $this->parseSchema($schema, "{$location}/properties/{$name}");
        }
        $required = isset($data['required'])
            ? array_map('strval', $this->list($data['required'], "{$location}/required"))
            : [];
        $additional = $data['additionalProperties'] ?? true;
        if (!is_bool($additional)) {
            $additional = $this->parseSchema($additional, "{$location}/additionalProperties");
        }

        return new ObjectSchema(...[
            'properties' => $properties,
            'required' => $required,
            'additionalProperties' => $additional,
            'minProperties' => $this->nullableInt($data['minProperties'] ?? null, "{$location}/minProperties"),
            'maxProperties' => $this->nullableInt($data['maxProperties'] ?? null, "{$location}/maxProperties"),
            ...$common,
        ]);
    }

    private function parseDiscriminator(array $data): ?Discriminator
    {
        if (!isset($data['discriminator'])) {
            return null;
        }
        if (is_string($data['discriminator'])) {
            return new Discriminator($data['discriminator']);
        }
        $value = $this->object($data['discriminator'], 'schema/discriminator');
        $mapping = [];
        foreach ($this->object($value['mapping'] ?? [], 'schema/discriminator/mapping') as $name => $reference) {
            if (!is_string($reference)) {
                throw new InvalidSpecification('Discriminator mappings must be strings');
            }
            $mapping[(string) $name] = $reference;
        }
        return new Discriminator($this->requiredString($value, 'propertyName', 'schema/discriminator'), $mapping);
    }

    protected function parseMediaTypes(mixed $raw, string $location): array
    {
        $content = [];
        foreach ($this->object($raw, $location) as $name => $item) {
            $data = $this->object($item, "{$location}/{$name}");
            $examples = [];
            foreach ($this->object($data['examples'] ?? [], "{$location}/{$name}/examples") as $exampleName => $exampleRaw) {
                $example = $this->resolveObject($exampleRaw, "{$location}/{$name}/examples/{$exampleName}");
                $examples[(string) $exampleName] = new Example(
                    summary: $this->optionalString($example, 'summary') ?? '',
                    description: $this->optionalString($example, 'description') ?? '',
                    value: $example['value'] ?? null,
                    externalValue: $this->optionalString($example, 'externalValue'),
                    extensions: $this->extensions($example),
                );
            }
            $encoding = [];
            foreach ($this->object($data['encoding'] ?? [], "{$location}/{$name}/encoding") as $property => $encodingRaw) {
                $value = $this->object($encodingRaw, "{$location}/{$name}/encoding/{$property}");
                $encoding[(string) $property] = new Encoding(
                    contentType: $this->optionalString($value, 'contentType'),
                    headers: $this->parseHeaders($value['headers'] ?? [], "{$location}/{$name}/encoding/{$property}/headers"),
                    style: $this->optionalString($value, 'style'),
                    explode: array_key_exists('explode', $value) ? (bool) $value['explode'] : null,
                    allowReserved: (bool) ($value['allowReserved'] ?? false),
                    extensions: $this->extensions($value),
                );
            }
            $content[(string) $name] = new MediaType(
                schema: array_key_exists('schema', $data) ? $this->parseSchema($data['schema'], "{$location}/{$name}/schema") : null,
                example: $data['example'] ?? null,
                examples: $examples,
                encoding: $encoding,
                extensions: $this->extensions($data),
            );
        }
        return $content;
    }

    /** @return array<string, Header> */
    protected function parseHeaders(mixed $raw, string $location, bool $openApi2 = false): array
    {
        $headers = [];
        foreach ($this->object($raw, $location) as $name => $item) {
            $data = $this->resolveObject($item, "{$location}/{$name}");
            $schema = null;
            if (array_key_exists('schema', $data)) {
                $schema = $this->parseSchema($data['schema'], "{$location}/{$name}/schema");
            } elseif ($openApi2 && isset($data['type'])) {
                $schema = $this->parseSchema($this->schemaFieldsFromParameter($data), "{$location}/{$name}");
            }
            $headers[(string) $name] = new Header(
                description: $this->optionalString($data, 'description') ?? '',
                required: (bool) ($data['required'] ?? false),
                deprecated: (bool) ($data['deprecated'] ?? false),
                schema: $schema,
                content: isset($data['content']) ? $this->parseMediaTypes($data['content'], "{$location}/{$name}/content") : [],
                style: $this->optionalString($data, 'style'),
                explode: array_key_exists('explode', $data) ? (bool) $data['explode'] : null,
                extensions: $this->extensions($data),
            );
        }
        return $headers;
    }

    /** @return array<string, mixed> */
    protected function resolveObject(mixed $raw, string $location): array
    {
        $data = $this->object($raw, $location);
        if (isset($data['$ref'])) {
            $reference = $this->requiredString($data, '$ref', $location);
            $data = $this->object($this->resolver->resolveObject($reference), $reference);
        }
        return $data;
    }

    /** @return array<string, mixed> */
    protected function schemaFieldsFromParameter(array $data): array
    {
        return array_intersect_key($data, array_flip([
            'type', 'format', 'items', 'default', 'enum', 'maximum', 'exclusiveMaximum',
            'minimum', 'exclusiveMinimum', 'maxLength', 'minLength', 'pattern',
            'maxItems', 'minItems', 'uniqueItems', 'multipleOf', 'description', 'x-nullable',
        ]));
    }

    protected function parseSecurityScheme(array $data, string $location, bool $openApi2 = false): SecurityScheme
    {
        $type = $this->requiredString($data, 'type', $location);
        if ($openApi2 && $type === 'basic') {
            return new SecurityScheme(SecuritySchemeType::HTTP, $this->optionalString($data, 'description') ?? '', scheme: 'basic', extensions: $this->extensions($data));
        }
        if ($type === 'apiKey') {
            $locationValue = $this->requiredString($data, 'in', $location);
            try {
                $parameterLocation = ParameterLocation::from($locationValue);
            } catch (\ValueError) {
                throw new InvalidSpecification("Unsupported API key location '{$locationValue}' at {$location}/in");
            }
            return new SecurityScheme(
                SecuritySchemeType::API_KEY,
                $this->optionalString($data, 'description') ?? '',
                name: $this->requiredString($data, 'name', $location),
                location: $parameterLocation,
                extensions: $this->extensions($data),
            );
        }
        if ($type === 'oauth2') {
            $flows = $openApi2 ? $this->parseOpenApi2OAuthFlows($data, $location) : $this->parseOAuthFlows($data['flows'] ?? [], "{$location}/flows");
            return new SecurityScheme(SecuritySchemeType::OAUTH2, $this->optionalString($data, 'description') ?? '', flows: $flows, extensions: $this->extensions($data));
        }
        if ($type === 'http') {
            return new SecurityScheme(
                SecuritySchemeType::HTTP,
                $this->optionalString($data, 'description') ?? '',
                scheme: strtolower($this->requiredString($data, 'scheme', $location)),
                bearerFormat: $this->optionalString($data, 'bearerFormat'),
                extensions: $this->extensions($data),
            );
        }
        if ($type === 'openIdConnect') {
            return new SecurityScheme(SecuritySchemeType::OPEN_ID_CONNECT, $this->optionalString($data, 'description') ?? '', openIdConnectUrl: $this->requiredString($data, 'openIdConnectUrl', $location), extensions: $this->extensions($data));
        }
        if ($type === 'mutualTLS' && $this->version === Version::V3_1) {
            return new SecurityScheme(SecuritySchemeType::MUTUAL_TLS, $this->optionalString($data, 'description') ?? '', extensions: $this->extensions($data));
        }
        throw new InvalidSpecification("Unsupported security scheme type '{$type}' at {$location}");
    }

    /** @return array<string, OAuthFlow> */
    private function parseOAuthFlows(mixed $raw, string $location): array
    {
        $flows = [];
        foreach ($this->object($raw, $location) as $name => $item) {
            $data = $this->object($item, "{$location}/{$name}");
            $flows[(string) $name] = new OAuthFlow(
                authorizationUrl: $this->optionalString($data, 'authorizationUrl'),
                tokenUrl: $this->optionalString($data, 'tokenUrl'),
                refreshUrl: $this->optionalString($data, 'refreshUrl'),
                scopes: $this->stringMap($data['scopes'] ?? [], "{$location}/{$name}/scopes"),
            );
        }
        return $flows;
    }

    /** @return array<string, OAuthFlow> */
    private function parseOpenApi2OAuthFlows(array $data, string $location): array
    {
        $flow = $this->requiredString($data, 'flow', $location);
        $name = match ($flow) {
            'implicit' => 'implicit',
            'password' => 'password',
            'application' => 'clientCredentials',
            'accessCode' => 'authorizationCode',
            default => throw new InvalidSpecification("Unsupported OAuth flow '{$flow}' at {$location}"),
        };
        return [$name => new OAuthFlow(
            authorizationUrl: $this->optionalString($data, 'authorizationUrl'),
            tokenUrl: $this->optionalString($data, 'tokenUrl'),
            scopes: $this->stringMap($data['scopes'] ?? [], "{$location}/scopes"),
        )];
    }

    /** @return array<string, string> */
    private function stringMap(mixed $raw, string $location): array
    {
        $result = [];
        foreach ($this->object($raw, $location) as $key => $value) {
            if (!is_string($value)) {
                throw new InvalidSpecification("Expected string at {$location}/{$key}");
            }
            $result[(string) $key] = $value;
        }
        return $result;
    }

    private function nullableInt(mixed $value, string $location): ?int
    {
        if ($value === null) {
            return null;
        }
        if (!is_int($value)) {
            throw new InvalidSpecification("Expected integer at {$location}");
        }
        return $value;
    }

    private function nullableNumber(mixed $value, string $location): int|float|null
    {
        if ($value === null) {
            return null;
        }
        if (!is_int($value) && !is_float($value)) {
            throw new InvalidSpecification("Expected number at {$location}");
        }
        return $value;
    }
}
