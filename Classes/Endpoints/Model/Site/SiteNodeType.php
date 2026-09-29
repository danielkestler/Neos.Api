<?php
declare(strict_types=1);

namespace Neos\Api\Endpoints\Model\Site;

use Neos\Api\Domain\Site\NodeTypeName;
use Neos\ContentRepository\Core\NodeType\NodeType;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * A node type a site node can have
 */
final readonly class SiteNodeType implements ProvidesSchema
{
    /**
     * @param string $label the label of the node type, which may be a translation id
     */
    public function __construct(
        public NodeTypeName $name,
        public string $label,
    ) {
    }

    public static function fromNodeType(NodeType $nodeType): self
    {
        return new self(NodeTypeName::fromString($nodeType->name->value), $nodeType->getLabel());
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
