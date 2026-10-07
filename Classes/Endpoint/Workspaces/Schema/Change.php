<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\Workspaces\Schema;

use Neos\Api\Endpoint\Nodes\Schema\DimensionSpacePoint;
use Neos\Api\Endpoint\Nodes\Schema\NodeAggregateId;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Neos\PendingChangesProjection;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * What changed about a node in a workspace compared to its base workspace, as Neos' pending changes projection keeps it
 */
final readonly class Change implements ProvidesSchema
{
    /**
     * @param DimensionSpacePoint|null $originDimensionSpacePoint the node variant that changed, null if the change is about the whole aggregate, e.g. its name or node type
     * @param bool $created whether the node was created in the workspace
     * @param bool $changed whether its properties, references or tags changed
     * @param bool $moved whether it was moved
     * @param bool $deleted whether it was removed
     */
    public function __construct(
        public NodeAggregateId $nodeAggregateId,
        public DimensionSpacePoint|null $originDimensionSpacePoint,
        public bool $created,
        public bool $changed,
        public bool $moved,
        public bool $deleted,
    ) {
    }

    public static function from(PendingChangesProjection\Change $change): self
    {
        return new self(
            NodeAggregateId::fromString($change->nodeAggregateId->value),
            $change->originDimensionSpacePoint !== null ? DimensionSpacePoint::from($change->originDimensionSpacePoint) : null,
            $change->created,
            $change->changed,
            $change->moved,
            $change->deleted,
        );
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
