<?php

declare(strict_types=1);

namespace Utopia\OpenAPI\Model;

final readonly class CompositeSchema extends Schema
{
    /** @var list<Schema> */
    public array $schemas;

    /** @param list<Schema> $schemas */
    public function __construct(
        public ?Composition $composition,
        array $schemas = [],
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
        $openEnumIndex = self::openStringEnumBranchIndex($composition, $schemas, $not, $enum);
        if ($openEnumIndex !== null) {
            /** @var StringSchema $enumBranch */
            $enumBranch = $schemas[$openEnumIndex];
            $schemas[$openEnumIndex] = new StringSchema(
                minLength: $enumBranch->minLength,
                maxLength: $enumBranch->maxLength,
                pattern: $enumBranch->pattern,
                title: $enumBranch->title,
                description: $enumBranch->description,
                nullable: $enumBranch->nullable,
                default: $enumBranch->default,
                enum: $enumBranch->enum,
                format: $enumBranch->format,
                readOnly: $enumBranch->readOnly,
                writeOnly: $enumBranch->writeOnly,
                deprecated: $enumBranch->deprecated,
                example: $enumBranch->example,
                extensions: $enumBranch->extensions,
                enumName: $enumBranch->enumName,
                enumKeys: $enumBranch->enumKeys,
                open: true,
            );
        }
        $this->schemas = $schemas;

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
        $index = self::openStringEnumBranchIndex($this->composition, $this->schemas, $this->not, $this->enum);

        return $index === null ? null : $this->schemas[$index];
    }

    /**
     * @param  list<Schema>  $schemas
     * @param  list<mixed>  $enum
     */
    private static function openStringEnumBranchIndex(?Composition $composition, array $schemas, ?Schema $not, array $enum): ?int
    {
        if ($composition !== Composition::ANY_OF || $not !== null || $enum !== []) {
            return null;
        }

        $enumBranchIndex = null;
        $hasOpenBranch = false;

        foreach ($schemas as $index => $schema) {
            if (! $schema instanceof StringSchema) {
                return null;
            }

            if ($schema->enum === []) {
                if (
                    $schema->minLength !== null
                    || $schema->maxLength !== null
                    || $schema->pattern !== null
                    || $schema->format !== null
                ) {
                    return null;
                }
                $hasOpenBranch = true;

                continue;
            }

            if ($enumBranchIndex !== null) {
                return null;
            }

            $enumBranchIndex = $index;
        }

        return $hasOpenBranch ? $enumBranchIndex : null;
    }
}
