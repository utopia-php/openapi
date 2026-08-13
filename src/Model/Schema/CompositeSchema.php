<?php

declare(strict_types=1);

namespace Utopia\OpenAPI\Model\Schema;

final readonly class CompositeSchema extends Schema
{
    /** @param list<Schema> $schemas */
    public function __construct(
        public ?Composition $composition,
        public array $schemas = [],
        public ?Schema $not = null,
        public ?Discriminator $discriminator = null,
        ?string $title = null,
        string $description = '',
        bool $nullable = false,
        mixed $default = null,
        array $enum = [],
        ?string $format = null,
        bool $readOnly = false,
        bool $writeOnly = false,
        bool $deprecated = false,
        mixed $example = null,
        array $extensions = [],
    ) {
        parent::__construct($title, $description, $nullable, $default, $enum, $format, $readOnly, $writeOnly, $deprecated, $example, $extensions);
    }
}
