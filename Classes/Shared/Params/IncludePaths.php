<?php
declare(strict_types=1);

namespace Neos\Api\Shared\Params;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\StringSchema;
use Neos\Schematic\Schematic;

/**
 * What to include in a resource beyond its own fields: a comma-separated list of paths
 */
final readonly class IncludePaths implements ProvidesSchema
{
    private function __construct(
        public string $value,
    ) {
    }

    public static function fromString(string $value): self
    {
        return Schematic::instantiate(self::class, $value)->valueOrThrow();
    }

    /**
     * @return list<string>
     */
    public function paths(): array
    {
        return array_values(array_unique(explode(',', $this->value)));
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= StringSchema::create(
            description: 'What to include beyond the resource\'s own fields, comma-separated, a path like children.references includes the ones it goes through',
            examples: ['references'],
            pattern: '^[a-zA-Z][a-zA-Z0-9.]*(,[a-zA-Z][a-zA-Z0-9.]*)*$',
        );
    }
}
