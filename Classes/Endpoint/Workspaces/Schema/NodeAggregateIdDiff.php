<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\Workspaces\Schema;

use Neos\Api\Endpoint\Nodes\Schema\NodeAggregateId;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * A node aggregate id changed by a workspace, compared to its base workspace, e.g. the parent of a moved node
 */
final readonly class NodeAggregateIdDiff implements ProvidesSchema
{
    /**
     * @param NodeAggregateId $old the id in the base workspace
     * @param NodeAggregateId $new the id in the workspace
     */
    public function __construct(
        public NodeAggregateId $old,
        public NodeAggregateId $new,
    ) {
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
