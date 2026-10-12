<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\Workspaces\Schema;

use Neos\Api\Endpoint\Nodes\Schema\NodeAggregateId;
use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\Feature\WorkspaceRebase\ConflictingEvent;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindClosestNodeFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\VisibilityConstraints;
use Neos\ContentRepository\Core\SharedModel;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Neos\Domain\Service\NodeTypeNameFactory;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * A change of a workspace that can't be applied to its base workspace (any more), e.g. to a node removed there
 */
final readonly class ConflictingChange implements ProvidesSchema
{
    /**
     * @param NodeAggregateId|null $nodeAggregateId the node the change is about, null if it isn't about one
     * @param NodeAggregateId|null $documentAggregateId the document the node is in (or is), to group the conflicts, null if unknown
     * @param NodeAggregateId|null $siteAggregateId the site the node is in, null if unknown
     * @param string|null $reason node_has_been_deleted if the node was removed in the base workspace, else null
     * @param string $message what the content repository says about it
     */
    public function __construct(
        public NodeAggregateId|null $nodeAggregateId,
        public NodeAggregateId|null $documentAggregateId,
        public NodeAggregateId|null $siteAggregateId,
        public string|null $reason,
        public string $message,
    ) {
    }

    /**
     * The document and site are looked up in the workspace (where the node still is), in any dimension space point
     * it covers, without visibility constraints: a conflict about a hidden or removed node must still be grouped
     */
    public static function from(ConflictingEvent $conflictingEvent, ContentRepository $contentRepository, SharedModel\Workspace\WorkspaceName $workspaceName): self
    {
        $nodeAggregateId = $conflictingEvent->getAffectedNodeAggregateId();
        $document = null;
        $site = null;
        if ($nodeAggregateId !== null) {
            try {
                $contentGraph = $contentRepository->getContentGraph($workspaceName);
                foreach ($contentGraph->findNodeAggregateById($nodeAggregateId)?->coveredDimensionSpacePoints ?? [] as $dimensionSpacePoint) {
                    $subgraph = $contentGraph->getSubgraph($dimensionSpacePoint, VisibilityConstraints::createEmpty());
                    $document = $subgraph->findClosestNode($nodeAggregateId, FindClosestNodeFilter::create(nodeTypes: NodeTypeNameFactory::NAME_DOCUMENT));
                    $site = $subgraph->findClosestNode($nodeAggregateId, FindClosestNodeFilter::create(nodeTypes: NodeTypeNameFactory::NAME_SITE));
                    break;
                }
            } catch (\Exception) {
                // the conflict is still worth reporting without them
            }
        }
        $exception = $conflictingEvent->getException();
        $shortName = substr(strrchr('\\' . $exception::class, '\\') ?: '', 1);
        return new self(
            $nodeAggregateId !== null ? NodeAggregateId::fromString($nodeAggregateId->value) : null,
            $document !== null ? NodeAggregateId::fromString($document->aggregateId->value) : null,
            $site !== null ? NodeAggregateId::fromString($site->aggregateId->value) : null,
            $shortName === 'NodeAggregateCurrentlyDoesNotExist' ? 'node_has_been_deleted' : null,
            $exception->getMessage(),
        );
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
