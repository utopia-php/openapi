<?php

declare(strict_types=1);

namespace Utopia\OpenAPI\Model;

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

    /**
     * Return the documented values from an open string enum.
     *
     * An open string enum uses anyOf to combine one string enum with one or
     * more string branches that accept values outside the documented set.
     */
    public function openStringEnumBranch(): ?StringSchema
    {
        if ($this->composition !== Composition::ANY_OF) {
            return null;
        }

        $enumBranch = null;
        $hasOpenBranch = false;

        foreach ($this->schemas as $schema) {
            if (! $schema instanceof StringSchema) {
                return null;
            }

            if ($schema->enum === []) {
                $hasOpenBranch = true;

                continue;
            }

            if ($enumBranch !== null) {
                return null;
            }

            $enumBranch = $schema;
        }

        return $hasOpenBranch ? $enumBranch : null;
    }
}
