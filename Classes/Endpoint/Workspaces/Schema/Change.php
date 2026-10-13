<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\Workspaces\Schema;

use Neos\Api\Endpoint\Nodes\Schema\DimensionSpacePoint;
use Neos\Api\Endpoint\Nodes\Schema\NodeAggregateId;
use Neos\Api\Endpoint\Workspaces\ChangeLocator;
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
     * @param NodeAggregateId|null $documentAggregateId the closest document of the node in the workspace, the node itself if it is one, null if there is none
     * @param NodeAggregateId|null $siteAggregateId the site the node is in, null if there is none
     * @param DimensionSpacePoint|null $originDimensionSpacePoint the node variant that changed, null if the change is about the whole aggregate, e.g. its name or node type
     * @param bool $created whether the node was created in the workspace
     * @param bool $changed whether its properties, references or tags changed
     * @param bool $moved whether it was moved
     * @param bool $deleted whether it was removed
     * @param ChangeDiff|null $diff what the change does to the node compared to the base workspace, null unless included
     */
    public function __construct(
        public NodeAggregateId $nodeAggregateId,
        public NodeAggregateId|null $documentAggregateId,
        public NodeAggregateId|null $siteAggregateId,
        public DimensionSpacePoint|null $originDimensionSpacePoint,
        public bool $created,
        public bool $changed,
        public bool $moved,
        public bool $deleted,
        public ChangeDiff|null $diff,
    ) {
    }

    /**
     * The change, located (its document and site) if a locator is given, without its diff (withDiff())
     */
    public static function from(PendingChangesProjection\Change $change, ChangeLocator|null $locator = null): self
    {
        // a legacy hard removal (before soft removals) names the surviving ancestor, as Neos' publishing scopes it
        $location = $locator?->locate($change->getLegacyRemovalAttachmentPoint() ?? $change->nodeAggregateId, $change->originDimensionSpacePoint);
        return new self(
            NodeAggregateId::fromString($change->nodeAggregateId->value),
            isset($location['document']) ? NodeAggregateId::fromString($location['document']->value) : null,
            isset($location['site']) ? NodeAggregateId::fromString($location['site']->value) : null,
            $change->originDimensionSpacePoint !== null ? DimensionSpacePoint::from($change->originDimensionSpacePoint) : null,
            $change->created,
            $change->changed,
            $change->moved,
            $change->deleted,
            null,
        );
    }

    public function withDiff(ChangeDiff $diff): self
    {
        return new self($this->nodeAggregateId, $this->documentAggregateId, $this->siteAggregateId, $this->originDimensionSpacePoint, $this->created, $this->changed, $this->moved, $this->deleted, $diff);
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
