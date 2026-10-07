<?php
declare(strict_types=1);

namespace Neos\Api\Infrastructure\ContentRepository;

use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\Feature\Security\Exception\AccessDenied;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Exception\WorkspaceDoesNotExist;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Security\Context;
use Neos\Neos\Domain\Model\Site;
use Neos\Neos\Domain\Service\NodeTypeNameFactory;
use Neos\Neos\Domain\SubtreeTagging\NeosVisibilityConstraints;
use Neos\Neos\Security\Authorization\ContentRepositoryAuthorizationService;

/**
 * The subgraphs the API reads nodes from, with the account's visibility
 */
#[Flow\Scope('singleton')]
final readonly class ContentSubgraphs
{
    public function __construct(
        private ContentRepositoryFinder $contentRepositoryFinder,
        private ContentRepositoryRegistry $contentRepositoryRegistry,
        private ContentRepositoryAuthorizationService $contentRepositoryAuthorizationService,
        private Context $securityContext,
    ) {
    }

    /**
     * The subgraph as the account sees it, null if the content repository or workspace doesn't exist or the account
     * may not read it: both are a 404, so workspaces can't be probed
     *
     * @param bool $excludeDisabled leave out disabled nodes even if the account may see them, as the frontend does
     *                              (Neos\Neos\Controller\Frontend\NodeController::showAction()), otherwise it's the
     *                              account's visibility as in the Neos backend and its preview (previewAction())
     */
    public function find(ContentRepositoryId $contentRepositoryId, WorkspaceName $workspaceName, DimensionSpacePoint $dimensionSpacePoint, bool $excludeDisabled): ?ContentSubgraphInterface
    {
        $contentRepository = $this->contentRepositoryFinder->find($contentRepositoryId);
        if ($contentRepository === null) {
            return null;
        }
        try {
            if (!$excludeDisabled) {
                return $contentRepository->getContentSubgraph($workspaceName, $dimensionSpacePoint);
            }
            $visibilityConstraints = $this->contentRepositoryAuthorizationService
                ->getVisibilityConstraints($contentRepository->id, $this->securityContext->getRoles())
                ->merge(NeosVisibilityConstraints::excludeDisabled());
            return $contentRepository->getContentGraph($workspaceName)->getSubgraph($dimensionSpacePoint, $visibilityConstraints);
        } catch (WorkspaceDoesNotExist | AccessDenied) {
            return null;
        }
    }

    /**
     * The site node of the site in the live workspace and the site's default dimension space point, the one the
     * frontend renders for its home page. Null if there is none or the account may not read it, see find()
     */
    public function findSiteNode(Site $site, bool $excludeDisabled): ?Node
    {
        $configuration = $site->getConfiguration();
        $subgraph = $this->find($configuration->contentRepositoryId, WorkspaceName::forLive(), $configuration->defaultDimensionSpacePoint, $excludeDisabled);
        return $subgraph !== null ? $this->findSiteNodeIn($subgraph, $site) : null;
    }

    /**
     * The site node of the site in the subgraph, null if there is none or the subgraph is of another content
     * repository
     */
    public function findSiteNodeIn(ContentSubgraphInterface $subgraph, Site $site): ?Node
    {
        if (!$subgraph->getContentRepositoryId()->equals($site->getConfiguration()->contentRepositoryId)) {
            return null;
        }
        $sitesNode = $subgraph->findRootNodeByType(NodeTypeNameFactory::forSites());
        return $sitesNode !== null ? $subgraph->findNodeByPath($site->getNodeName()->toNodeName(), $sitesNode->aggregateId) : null;
    }

    /**
     * The node in the other dimension space points its aggregate occupies (its content variants, not the points that
     * only fall back to it), in the order of the content dimensions, each read in that point as the account sees it,
     * so a variant the account may not see is left out
     *
     * @return list<Node>
     */
    public function findVariants(Node $node, bool $excludeDisabled): array
    {
        $contentRepository = $this->contentRepositoryRegistry->get($node->contentRepositoryId);
        $nodeAggregate = $contentRepository->getContentGraph($node->workspaceName)->findNodeAggregateById($node->aggregateId);
        $variants = [];
        // the occupied points are in no particular order
        foreach ($contentRepository->getVariationGraph()->getDimensionSpacePoints() as $dimensionSpacePoint) {
            $origin = OriginDimensionSpacePoint::fromDimensionSpacePoint($dimensionSpacePoint);
            if ($nodeAggregate?->occupiesDimensionSpacePoint($origin) !== true || $origin->equals($node->originDimensionSpacePoint)) {
                continue;
            }
            $variant = $this->find($node->contentRepositoryId, $node->workspaceName, $dimensionSpacePoint, $excludeDisabled)
                ?->findNodeById($node->aggregateId);
            if ($variant !== null) {
                $variants[] = $variant;
            }
        }
        return $variants;
    }
}
