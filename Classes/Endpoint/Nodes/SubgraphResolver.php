<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\Nodes;

use Neos\Api\Endpoint\Nodes\Schema\DimensionSpacePoint;
use Neos\Api\Infrastructure\ContentRepository\ContentRepositoryFinder;
use Neos\Api\Infrastructure\ContentRepository\ContentSubgraphs;
use Neos\Api\Infrastructure\ContentRepository\DimensionSpacePointFinder;
use Neos\Api\Shared\Response\BadRequest;
use Neos\Api\Shared\Response\NotFound;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\Flow\Annotations as Flow;

/**
 * The subgraph a request reads nodes from: its content repository, workspaceName and dimensionSpacePoint, the latter
 * by default as DimensionSpacePointFinder::findDefault(), as 400s and 404s where they fail
 */
#[Flow\Scope('singleton')]
final readonly class SubgraphResolver
{
    public function __construct(
        private ContentRepositoryFinder $contentRepositoryFinder,
        private ContentSubgraphs $contentSubgraphs,
        private DimensionSpacePointFinder $dimensionSpacePointFinder,
    ) {
    }

    /**
     * The subgraph as the account sees it (see ContentSubgraphs::find()), a 400 if the dimension space point is
     * invalid, not one of the content repository or can't be defaulted, a 404 if there is no such content repository
     * or workspace the account may read
     */
    public function resolve(ContentRepositoryId $contentRepositoryId, WorkspaceName $workspaceName, ?DimensionSpacePoint $dimensionSpacePoint, bool $excludeDisabled): ContentSubgraphInterface|BadRequest|NotFound
    {
        // a syntax error is a 400 before anything is looked up
        try {
            $point = $dimensionSpacePoint?->toDimensionSpacePoint();
        } catch (\InvalidArgumentException | \RuntimeException | \TypeError $exception) {
            return BadRequest::because(sprintf('dimensionSpacePoint is invalid: %s', $exception->getMessage()));
        }
        $contentRepository = $this->contentRepositoryFinder->find($contentRepositoryId);
        if ($contentRepository === null) {
            return NotFound::because(sprintf('There is no content repository with the ID %s', $contentRepositoryId->value));
        }
        $point ??= $this->dimensionSpacePointFinder->findDefault($contentRepository);
        if ($point === null) {
            return BadRequest::because(sprintf('The content repository %s has no site whose default dimension space point applies, give dimensionSpacePoint', $contentRepositoryId->value));
        }
        if (!$contentRepository->getVariationGraph()->getDimensionSpacePoints()->contains($point)) {
            return BadRequest::because(sprintf('There is no dimension space point %s in the content repository %s, GET /cr/%2$s lists its dimensions and their values', $point->toJson(), $contentRepositoryId->value));
        }
        return $this->contentSubgraphs->find($contentRepositoryId, $workspaceName, $point, $excludeDisabled)
            ?? NotFound::because(sprintf('There is no workspace %s in the content repository %s', $workspaceName->value, $contentRepositoryId->value));
    }
}
