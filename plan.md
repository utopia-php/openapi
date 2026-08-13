# Utopia OpenAPI

## Summary

`utopia-php/openapi` will be a lightweight, framework-independent PHP library for parsing API description documents into one immutable, typed model.

Supported input formats:

- OpenAPI 2.0 (formerly Swagger 2.0)
- OpenAPI 3.0
- OpenAPI 3.1

All formats will produce the same `Utopia\OpenAPI\Specification` model. Consumers should not need to know which source format was parsed after parsing completes.

```text
OpenAPI 2.0 (Swagger 2.0) ─┐
OpenAPI 3.0                 ├── Parser ──> Specification
OpenAPI 3.1                 ┘
```

## Identity

| Item | Value |
| --- | --- |
| Repository | `utopia-php/openapi` |
| Composer package | `utopia-php/openapi` |
| PHP namespace | `Utopia\OpenAPI` |
| Main parser | `Utopia\OpenAPI\Parser` |
| Canonical output | `Utopia\OpenAPI\Specification` |

The package is named `openapi` because Swagger 2.0 is now OpenAPI 2.0. “Swagger” also refers to a broader collection of tools, which this library will not integrate with.

## Goals

1. Parse supported OpenAPI versions directly into one canonical model.
2. Preserve standard OpenAPI semantics across source versions.
3. Provide immutable, typed objects suitable for generators, documentation tools, validators, and other consumers.
4. Preserve security requirement alternatives and schema composition accurately.
5. Handle local references safely without recursively expanding valid recursive schemas.
6. Remain independent of Appwrite, SDK Generator, and any particular framework.
7. Avoid converting one source document into another JSON specification as an intermediate representation.
8. Keep parsing deterministic and free from implicit network access.

## Non-goals

The library will not own or interpret:

- Appwrite-specific behavior
- `x-appwrite` semantics
- SDK method aliases
- SDK platform inclusion or exclusion
- Client, server, console, or manager packaging
- Package names or repository metadata
- Authentication setter names or ordering
- Generated file layouts
- SDK templates
- Demo application paths
- Swagger UI, Swagger Editor, or Swagger Codegen integration
- Swagger 1.x unless a concrete consumer requires it

SDK packaging and backward-compatibility policy belong to SDK generators, not an API description parser.

## Design principles

### One semantic model

OpenAPI 2.0, 3.0, and 3.1 readers will construct the same model directly.

Do not use this pipeline:

```text
Swagger JSON -> converted OpenAPI JSON -> OpenAPI reader -> Specification
```

Use this pipeline:

```text
OpenAPI 2.0 reader ─┐
OpenAPI 3.0 reader  ├──> Specification
OpenAPI 3.1 reader  ┘
```

Readers may share internal builders and parsing utilities, but no reader should emit another specification document.

### Model API semantics, not source layout

Equivalent concepts should have the same representation regardless of their source syntax.

For example, these inputs:

```yaml
# OpenAPI 2.0
schemes: [https]
host: api.example.com
basePath: /v1
```

```yaml
# OpenAPI 3.x
servers:
  - url: https://api.example.com/v1
```

should both produce:

```php
new Server(url: 'https://api.example.com/v1');
```

### No implicit I/O

The core parser accepts document content or decoded data. It does not fetch URLs or read files implicitly.

```php
$json = file_get_contents($path);
$specification = Parser::parse($json);
```

External reference resolution, if added, must be explicit and supplied by the caller.

### Extensions are opaque

Standard OpenAPI extensions may be preserved:

```php
$operation->extensions;
```

The library must not assign domain-specific meaning to any vendor extension. In particular, it must never interpret `x-appwrite`.

## Public API

### Basic parsing

```php
use Utopia\OpenAPI\Parser;

$specification = Parser::parse($json);
```

The parser detects the version from either:

```json
{"swagger":"2.0"}
```

or:

```json
{"openapi":"3.1.0"}
```

Decoded arrays should also be accepted:

```php
$specification = Parser::parse($document);
```

### Explicit version

Callers may optionally provide the expected version:

```php
$specification = Parser::parse(
    input: $document,
    version: Version::V3_1,
);
```

An explicit version that disagrees with the document should fail with a controlled exception.

### Version model

```php
enum Version: string
{
    case V2 = '2.0';
    case V3_0 = '3.0';
    case V3_1 = '3.1';
}
```

Patch versions remain available as source metadata if needed, while semantic parsing is selected by the supported minor version.

### Parser configuration

The initial API should remain small. A configurable parser can support optional reference resolution later:

```php
$parser = new Parser(
    resolver: new LocalResolver(),
);

$specification = $parser->read($document);
```

Static `Parser::parse()` should use safe defaults.

## Proposed package structure

```text
src/
├── Parser.php
├── Specification.php
├── Version.php
├── Exception/
│   ├── ParseException.php
│   ├── InvalidSpecification.php
│   ├── UnsupportedVersion.php
│   ├── ReferenceNotFound.php
│   └── CircularReference.php
├── Parser/
│   ├── Reader.php
│   ├── OpenAPI2.php
│   ├── OpenAPI30.php
│   └── OpenAPI31.php
├── Model/
│   ├── Info.php
│   ├── Contact.php
│   ├── License.php
│   ├── Server.php
│   ├── ServerVariable.php
│   ├── Tag.php
│   ├── ExternalDocumentation.php
│   ├── PathItem.php
│   ├── Operation.php
│   ├── HttpMethod.php
│   ├── Parameter.php
│   ├── ParameterLocation.php
│   ├── RequestBody.php
│   ├── Response.php
│   ├── Header.php
│   ├── MediaType.php
│   ├── Encoding.php
│   ├── Example.php
│   ├── SecurityScheme.php
│   └── SecurityRequirement.php
├── Model/Schema/
│   ├── Schema.php
│   ├── AnySchema.php
│   ├── StringSchema.php
│   ├── IntegerSchema.php
│   ├── NumberSchema.php
│   ├── BooleanSchema.php
│   ├── ObjectSchema.php
│   ├── ArraySchema.php
│   ├── ReferenceSchema.php
│   ├── CompositeSchema.php
│   ├── Composition.php
│   └── Discriminator.php
└── Reference/
    ├── Reference.php
    ├── Resolver.php
    ├── ResolutionContext.php
    └── LocalResolver.php
```

The final structure may be simplified as the implementation reveals which distinctions are useful to consumers.

## Canonical specification model

A preliminary shape:

```php
final readonly class Specification
{
    /**
     * @param list<Server> $servers
     * @param array<string, Tag> $tags
     * @param array<string, PathItem> $paths
     * @param array<string, Schema> $schemas
     * @param array<string, SecurityScheme> $securitySchemes
     * @param list<SecurityRequirement> $security
     * @param array<string, mixed> $extensions
     */
    public function __construct(
        public Version $version,
        public Info $info,
        public array $servers,
        public array $tags,
        public array $paths,
        public array $schemas,
        public array $securitySchemes,
        public array $security,
        public array $extensions = [],
    ) {
    }
}
```

The model should be immutable. Collections should document whether they are ordered lists or name-indexed maps.

## Metadata

```php
final readonly class Info
{
    public function __construct(
        public string $title,
        public string $description,
        public string $version,
        public ?string $termsOfService,
        public ?Contact $contact,
        public ?License $license,
        public array $extensions = [],
    ) {
    }
}
```

The source document version and API release version are different concepts:

- `Specification::$version` identifies OpenAPI 2.0, 3.0, or 3.1.
- `Specification::$info->version` identifies the described API version.

## Paths and operations

The library should expose paths and standard HTTP operations without introducing an SDK-specific service concept.

```php
enum HttpMethod: string
{
    case GET = 'get';
    case POST = 'post';
    case PUT = 'put';
    case PATCH = 'patch';
    case DELETE = 'delete';
    case HEAD = 'head';
    case OPTIONS = 'options';
    case TRACE = 'trace';
}
```

```php
final readonly class Operation
{
    /**
     * @param list<string> $tags
     * @param list<Parameter> $parameters
     * @param array<string, Response> $responses
     * @param list<SecurityRequirement> $security
     * @param array<string, mixed> $extensions
     */
    public function __construct(
        public string $id,
        public HttpMethod $method,
        public string $path,
        public array $tags,
        public string $summary,
        public string $description,
        public bool $deprecated,
        public array $parameters,
        public ?RequestBody $requestBody,
        public array $responses,
        public array $security,
        public array $extensions = [],
    ) {
    }
}
```

### Tags, not services

OpenAPI defines tags. It does not define SDK services. The library should preserve:

```php
$specification->tags;
$operation->tags;
```

A consumer may group operations itself:

```php
$operations = $specification->operationsByTag('account');
```

Any convenience method must remain neutral and must not create platform or SDK semantics.

### Path-level parameters

Path-level parameters are inherited by operations. An operation-level parameter with the same `name` and `in` overrides the inherited parameter.

Reference objects must be resolved enough to compare parameter identity. Resolution must be cycle-safe and must handle JSON Pointer escaping.

## Requests

The canonical request model should normalize source-version differences.

OpenAPI 2.0 body parameters and OpenAPI 3.x request bodies should both produce `RequestBody`:

```php
final readonly class RequestBody
{
    /** @param array<string, MediaType> $content */
    public function __construct(
        public string $description,
        public bool $required,
        public array $content,
        public array $extensions = [],
    ) {
    }
}
```

OpenAPI 2.0 `consumes` should become request content/media types. Form-data parameters should retain their distinct encoding and file semantics.

Non-body parameters remain typed `Parameter` objects with locations such as:

- Path
- Query
- Header
- Cookie where supported

## Responses

```php
final readonly class Response
{
    /**
     * @param array<string, Header> $headers
     * @param array<string, MediaType> $content
     */
    public function __construct(
        public string $description,
        public array $headers,
        public array $content,
        public array $extensions = [],
    ) {
    }
}
```

OpenAPI 2.0 `produces` should become response content/media types. Status codes and the `default` response must retain their source ordering and identity.

## Security

Security requirements must preserve OpenAPI’s logical semantics.

```yaml
security:
  - Project: []
    Session: []
  - Project: []
    JWT: []
```

means:

```text
(Project AND Session) OR (Project AND JWT)
```

It must produce two requirements:

```php
[
    new SecurityRequirement([
        'Project' => [],
        'Session' => [],
    ]),
    new SecurityRequirement([
        'Project' => [],
        'JWT' => [],
    ]),
]
```

Do not flatten alternatives into a single scheme list.

Operation security behavior must follow OpenAPI inheritance:

- Missing operation `security` inherits root security.
- An explicit empty operation `security: []` disables inherited security.
- An empty requirement object represents anonymous access as one alternative.

### Security schemes

The canonical model should cover:

- API keys in headers, queries, and cookies
- HTTP basic authentication
- HTTP bearer authentication
- OAuth 2 flows and scopes
- OpenID Connect
- OpenAPI 2.0 basic and OAuth 2 definitions

Source-version differences should be normalized without losing semantic information.

## Schemas

Schemas should be typed rather than retained as arbitrary arrays. The model must account for differences between OpenAPI 3.0’s schema dialect and OpenAPI 3.1’s JSON Schema vocabulary.

Potential common base:

```php
abstract readonly class Schema
{
    /**
     * @param list<mixed> $enum
     * @param array<string, mixed> $extensions
     */
    public function __construct(
        public ?string $title,
        public string $description,
        public bool $nullable,
        public mixed $default,
        public array $enum,
        public array $extensions = [],
    ) {
    }
}
```

Expected concrete concepts:

- String
- Integer
- Number
- Boolean
- Object
- Array
- Reference
- Composite/union
- Unconstrained/any

The exact class hierarchy should be validated against real consumer operations before it is made stable.

### Nullability

Equivalent nullability forms should become one semantic representation:

```yaml
# OpenAPI 3.0
nullable: true
```

```yaml
# OpenAPI 3.1
 type: [string, "null"]
```

The canonical model should expose nullability without rewriting either form into vendor extensions.

### Composition

The model must preserve distinctions among:

- `oneOf`
- `anyOf`
- `allOf`
- `not`

```php
enum Composition: string
{
    case ONE_OF = 'oneOf';
    case ANY_OF = 'anyOf';
    case ALL_OF = 'allOf';
}
```

A discriminator and its mapping must remain attached to the relevant composite schema.

### Additional properties

Object schemas must distinguish:

- Additional properties forbidden
- Additional properties allowed with any shape
- Additional properties constrained by a schema

A plain boolean is insufficient if the value may itself be a schema.

## References

### Local references

The first stable release should support local JSON Pointer references such as:

```yaml
$ref: '#/components/schemas/User'
```

and the OpenAPI 2.0 equivalent:

```yaml
$ref: '#/definitions/User'
```

The library must implement JSON Pointer escaping:

- `~1` means `/`
- `~0` means `~`

### Do not expand schema graphs

Recursive schemas are valid:

```text
User -> Manager -> User
```

Schema references should normally remain references:

```php
new ReferenceSchema('#/components/schemas/User');
```

Consumers may resolve them explicitly when needed.

### Cycles

Reference traversal used for validation or parameter identity must track visited references and throw a controlled `CircularReference` exception for invalid cycles.

### External references

External references are a later, opt-in capability:

```php
interface Resolver
{
    public function resolve(
        Reference $reference,
        ResolutionContext $context,
    ): mixed;
}
```

Possible future resolvers:

- File resolver
- HTTP resolver
- Composite resolver

No external resolver should perform network access unless explicitly configured by the caller.

## OpenAPI 2.0 mapping

The OpenAPI 2.0 reader should map directly into the canonical model.

| OpenAPI 2.0 field | Canonical concept |
| --- | --- |
| `swagger` | `Version::V2` |
| `host`, `basePath`, `schemes` | `Server` values |
| `definitions` | Schemas |
| `parameters` with `in: body` | Request body |
| `formData` parameters | Form media type and encodings |
| `consumes` | Request media types |
| `produces` | Response media types |
| `responses.*.schema` | Response media schema |
| `securityDefinitions` | Security schemes |
| Root or operation `security` | Security requirements |

Global, path-level, and operation-level `consumes`, `produces`, parameters, and security must follow their standard inheritance and override rules.

## OpenAPI 3.0 and 3.1 differences

The readers should share behavior where the specifications agree while preserving meaningful differences.

Important differences include:

- OpenAPI 3.0 schema dialect versus OpenAPI 3.1 JSON Schema compatibility
- `nullable` in 3.0 versus null types in 3.1
- `example` and `examples`
- Schema keywords introduced or clarified in 3.1
- `webhooks` in 3.1
- Top-level `jsonSchemaDialect` in 3.1

Unsupported standard fields should not be silently assigned incorrect semantics. They should either be preserved, reported through diagnostics, or deliberately deferred and documented.

## Parsing and validation

Parsing and full OpenAPI validation are separate concerns.

The parser must reject conditions that prevent a coherent model, including:

- Invalid JSON input
- Missing or unsupported specification version
- Structurally invalid required fields
- Invalid local references needed during parsing
- Invalid parameter reference cycles

The first release does not need to be a complete OpenAPI conformance validator.

A later API may return diagnostics:

```php
final readonly class ParseResult
{
    /** @param list<Diagnostic> $diagnostics */
    public function __construct(
        public Specification $specification,
        public array $diagnostics,
    ) {
    }
}
```

Potential diagnostics include:

- Missing operation IDs
- Duplicate operation IDs
- Unknown security schemes
- Missing path parameters
- Unsupported schema keywords
- Unresolved optional references

The initial stable API may return `Specification` directly and use exceptions for fatal errors.

## Exception model

All public exceptions should derive from one package exception contract:

```php
interface OpenAPIException extends Throwable
{
}
```

Expected exceptions:

- `ParseException`
- `InvalidSpecification`
- `UnsupportedVersion`
- `ReferenceNotFound`
- `CircularReference`

Exceptions should include a useful document location or reference where possible.

## Dependencies and compatibility

The library should remain small and usable throughout the Utopia ecosystem.

Initial preferences:

- PHP version aligned with current Utopia package policy
- `ext-json` as the only required parsing extension
- No required framework dependencies
- No required HTTP client
- No implicit file or network access
- JSON input first
- YAML support optional and isolated behind an adapter if later required

Potential development tooling:

- PHPUnit
- PHPStan
- Rector
- Pint or the standard Utopia formatter configuration

No dependency should be added until its need is demonstrated.

## Testing strategy

### Unit fixtures

Maintain small fixtures for focused behavior:

- Metadata
- Servers
- Tags and operations
- Parameter inheritance and overrides
- Request bodies
- Responses
- Security alternatives
- Security inheritance
- Primitive schemas
- Object and array schemas
- Enums
- Nullability
- Composition and discriminators
- Local references
- Recursive schemas
- Invalid reference cycles
- Extensions

### Cross-version parity

Equivalent OpenAPI 2.0, 3.0, and 3.1 fixtures should parse to equivalent canonical models.

```text
fixture-openapi2.json  ─┐
fixture-openapi30.json ─┼──> equal semantic model
fixture-openapi31.json ─┘
```

Differences that are inherent to a source version should be asserted explicitly rather than hidden.

### Real-world fixtures

Once the core is stable, test public specifications from multiple projects. Appwrite specifications may be one fixture source, but no Appwrite behavior should enter library code.

### Round-trip behavior

Serialization is not an initial goal. If later added, round-trip tests must distinguish semantic equality from textual equality.

## Delivery sequence

### Phase 1: Foundation

- Composer package and namespace
- Version detection
- Common immutable models for metadata, servers, tags, paths, and operations
- OpenAPI 3.0 and 3.1 readers for those fields
- Security requirements with correct AND/OR semantics
- Local JSON Pointer support
- Unit tests

### Phase 2: Schema model

- Primitive schemas
- Object and array schemas
- References
- Enums and defaults
- OpenAPI 3.0 and 3.1 nullability
- `oneOf`, `anyOf`, and `allOf`
- Discriminators
- Recursive schema tests

### Phase 3: Requests and responses

- Path and operation parameters
- Parameter inheritance and overrides
- Request bodies
- Media types and encodings
- Responses and headers
- Examples
- Multipart and binary schemas

### Phase 4: OpenAPI 2.0

- Metadata and server mapping
- Definitions
- Body and form-data parameters
- `consumes` and `produces`
- Responses
- Security definitions
- Cross-version parity tests

### Phase 5: Hardening

- Better exception locations
- Diagnostics where useful
- Broader real-world fixtures
- Performance and memory checks
- Public API review
- First stable release

### Future phases

Only when required:

- Explicit external reference resolvers
- Optional YAML adapter
- Callbacks and webhooks
- Link and callback models
- Serialization
- More complete conformance validation

## SDK Generator integration

Integration belongs in `appwrite/sdk-generator`, not this library.

SDK Generator will depend on:

```json
{
  "require": {
    "utopia-php/openapi": "^1.0"
  }
}
```

Initial migration flow:

```text
OpenAPI document
      ↓
utopia-php/openapi
      ↓
Utopia\OpenAPI\Specification
      ↓
SDK Generator compatibility adapter
      ↓
Existing templates
```

The adapter is an in-memory SDK Generator concern. It may temporarily expose the existing template-facing structure while generation is migrated section by section.

Final flow:

```text
OpenAPI document
      ↓
utopia-php/openapi
      ↓
Utopia\OpenAPI\Specification
      ↓
SDK target policy
      ↓
SDK templates
```

SDK Generator remains responsible for behavior not represented by OpenAPI:

- Legacy method aliases
- Target-specific operation selection
- Package and repository metadata
- Generated file policy
- Backward-compatible naming

These rules must not be added to this package.

## Migration relationship to existing work

The experimental native reader in SDK Generator should not become the long-term parser implementation. Its generic findings can inform this package:

- Official-field parsing
- Typed immutable models
- Security alternative preservation
- Parameter inheritance and override handling
- JSON Pointer handling
- Reference-cycle protection
- Compatibility testing at the consumer boundary

After this package exposes a sufficient model, SDK Generator should replace its internal reader with this dependency.

## Decisions

The following decisions are established:

1. The repository and package name are `utopia-php/openapi`.
2. Swagger 2.0 is supported as OpenAPI 2.0.
3. Swagger 1.x is excluded unless a real requirement appears.
4. All supported versions produce one `Specification` model.
5. Readers construct the model directly without intermediate specification JSON.
6. The package is independent of Appwrite and SDK generation policy.
7. Vendor extensions may be preserved but are never interpreted.
8. Network and filesystem access are not implicit parser behavior.
9. Security alternatives retain OpenAPI AND/OR semantics.
10. Schema references remain references and support recursive models.
11. The package models tags, not SDK services.

## Questions to resolve during implementation

1. Should the first release target the current Utopia-wide PHP baseline or a lower reusable baseline?
2. Should extension maps be present on every extensible model or provided through a shared trait/interface?
3. Should component objects use dedicated collection classes or documented arrays?
4. How much JSON Schema 2020-12 behavior should OpenAPI 3.1 expose in the first stable release?
5. Should missing `operationId` be accepted, diagnosed, or rejected?
6. Should local non-schema references be preserved or eagerly resolved into typed component objects?
7. Should callbacks and webhooks be part of the first stable model or deferred?
8. Should optional YAML support live in this package or a separate integration package?

These questions should be answered using concrete consumer needs while keeping the core model standards-based and reusable.
