<?php
declare(strict_types=1);

namespace Neos\Api\Infrastructure\ContentRepository;

use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\Feature\Security\Exception\AccessDenied;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Exception\WorkspaceDoesNotExist;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Security\Context;
use Neos\Neos\Domain\SubtreeTagging\NeosVisibilityConstraints;
use Neos\Neos\Security\Authorization\ContentRepositoryAuthorizationService;

/**
 * The subgraphs the API reads nodes from, with the account's visibility
 */
#[Flow\Scope('singleton')]
final readonly class ContentSubgraphs
{
    public function __construct(
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
        if (!$this->contentRepositoryExists($contentRepositoryId)) {
            return null;
        }
        $contentRepository = $this->contentRepositoryRegistry->get($contentRepositoryId);
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

    private function contentRepositoryExists(ContentRepositoryId $contentRepositoryId): bool
    {
        // checked up front: the registry throws for an unknown one
        foreach ($this->contentRepositoryRegistry->getContentRepositoryIds() as $id) {
            if ($id->equals($contentRepositoryId)) {
                return true;
            }
        }
        return false;
    }
}
