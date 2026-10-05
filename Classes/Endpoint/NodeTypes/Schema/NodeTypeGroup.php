<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\NodeTypes\Schema;

use Neos\Api\Infrastructure\I18n\Labels;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * A group the Neos UI offers node types in for creation, a node type's ui.group
 */
final readonly class NodeTypeGroup implements ProvidesSchema
{
    /**
     * @param string $name the key in the Neos.Neos.nodeTypes.groups settings, e.g. general
     * @param string $label the label in the requested language, the name if it has none
     * @param bool $collapsed whether the Neos UI shows the group collapsed
     */
    public function __construct(
        public string $name,
        public string $label,
        public bool $collapsed,
    ) {
    }

    /**
     * @param array<string, mixed> $configuration
     */
    public static function from(string $name, array $configuration, Labels $labels): self
    {
        return new self(
            $name,
            $labels->label($configuration['label'] ?? null) ?? $name,
            (bool)($configuration['collapsed'] ?? false),
        );
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
