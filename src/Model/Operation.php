<?php

declare(strict_types=1);

namespace Utopia\OpenAPI\Model;

final readonly class Operation
{
    /**
     * @param list<string> $tags
     * @param list<Parameter> $parameters
     * @param array<string, Response> $responses
     * @param list<SecurityRequirement> $security
     * @param list<Server> $servers
     * @param array<string, mixed> $extensions
     */
    public function __construct(
        public string $id,
        public HttpMethod $method,
        public string $path,
        public array $tags = [],
        public string $summary = '',
        public string $description = '',
        public bool $deprecated = false,
        public array $parameters = [],
        public ?RequestBody $requestBody = null,
        public array $responses = [],
        public array $security = [],
        public array $servers = [],
        public ?ExternalDocumentation $externalDocumentation = null,
        public array $extensions = [],
    ) {
    }
}
