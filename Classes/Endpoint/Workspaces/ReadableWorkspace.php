<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\Workspaces;

use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentGraphInterface;
use Neos\ContentRepository\Core\SharedModel;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Security\Policy\Role;
use Neos\Neos\Security\Authorization\ContentRepositoryAuthorizationService;

/**
 * A workspace the account may read, and which of its node aggregates it may read
 */
#[Flow\Proxy(false)]
final class ReadableWorkspace
{
    /**
     * @var array<string, bool> whether each node aggregate is readable, by id, filled while asked
     */
    private array $readableNodeAggregates = [];

    /**
     * @param array<Role> $roles the token's account's
     */
    public function __construct(
        public readonly ContentRepository $contentRepository,
        public readonly SharedModel\Workspace\Workspace $workspace,
        private readonly ContentGraphInterface $contentGraph,
        private readonly array $roles,
        private readonly ContentRepositoryAuthorizationService $contentRepositoryAuthorizationService,
    ) {
    }

    /**
     * Whether the account may read one of the aggregate's nodes in the workspace: what happened to an aggregate is
     * about all of its nodes, so one readable node is enough. An aggregate that no longer exists in the workspace has
     * no permissions left, so whoever may read the workspace may read it
     */
    public function mayReadNodeAggregate(SharedModel\Node\NodeAggregateId $nodeAggregateId): bool
    {
        return $this->readableNodeAggregates[$nodeAggregateId->value] ??= (function () use ($nodeAggregateId): bool {
            $nodeAggregate = $this->contentGraph->findNodeAggregateById($nodeAggregateId);
            if ($nodeAggregate === null) {
                return true;
            }
            foreach ($nodeAggregate->getNodes() as $node) {
                if ($this->contentRepositoryAuthorizationService->getNodePermissions($node, $this->roles)->read) {
                    return true;
                }
            }
            return false;
        })();
    }
}
