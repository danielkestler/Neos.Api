<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\Workspaces;

use Neos\Api\Endpoint\ContentRepositories\Schema\ContentRepositoryId;
use Neos\Api\Endpoint\Workspaces\Schema\Workspace;
use Neos\Api\Endpoint\Workspaces\Schema\WorkspaceList;
use Neos\Api\Endpoint\Workspaces\Schema\WorkspaceListing;
use Neos\Api\Infrastructure\ContentRepository\ContentRepositoryFinder;
use Neos\Api\Security\AccountPrivileges;
use Neos\Api\Security\ApiAuthContextProvider;
use Neos\Api\Security\ApiCaller;
use Neos\Api\Security\ApiScopes;
use Neos\Api\Shared\Response\NotFound;
use Neos\ContentRepository\Core\SharedModel;
use Neos\Neos\Domain\Model;
use Neos\Neos\Domain\Service\WorkspaceService;
use Neos\Neos\Security\Authorization\ContentRepositoryAuthorizationService;
use Neos\OpenApi\Attributes\AuthContext;
use Neos\OpenApi\Attributes\Operation;
use Neos\Party\Domain\Service\PartyService;

/**
 * The workspaces of a content repository, live and the ones changes are made in before they're published
 */
final readonly class Workspaces
{
    public function __construct(
        private ContentRepositoryFinder $contentRepositoryFinder,
        private WorkspaceService $workspaceService,
        private ContentRepositoryAuthorizationService $contentRepositoryAuthorizationService,
        private AccountPrivileges $accountPrivileges,
        private PartyService $partyService,
    ) {
    }

    #[Operation(
        path: '/contentrepositories/{contentRepositoryId}/workspaces',
        method: 'GET',
        summary: 'List the workspaces',
        description: 'The workspaces of the content repository the account may read, by its workspace roles or as the owner, sorted by name, with their Neos metadata and what the account may do in them. Neos administrators may manage every workspace, but read only the ones a role grants them.',
        operationId: 'listWorkspaces',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::WORKSPACES_READ],
        ],
    )]
    public function list(
        ContentRepositoryId $contentRepositoryId,
        #[AuthContext] ApiCaller $caller,
    ): WorkspaceListing|NotFound {
        $contentRepository = $this->contentRepositoryFinder->find($contentRepositoryId->toContentRepositoryId());
        if ($contentRepository === null) {
            return NotFound::because(sprintf('There is no content repository with the ID %s', $contentRepositoryId->value));
        }
        // the token's account alone, as for its privileges, not Flow's security context
        $roles = $this->accountPrivileges->rolesOf($caller->account);
        $user = $this->partyService->getAssignedPartyOfAccount($caller->account);
        $userId = $user instanceof Model\User ? $user->getId() : null;
        $workspaces = [];
        foreach ($contentRepository->findWorkspaces() as $workspace) {
            $permissions = $this->contentRepositoryAuthorizationService->getWorkspacePermissions($contentRepository->id, $workspace->workspaceName, $roles, $userId);
            if (!$permissions->read) {
                continue;
            }
            $workspaces[$workspace->workspaceName->value] = Workspace::from(
                $workspace,
                $this->workspaceService->getWorkspaceMetadata($contentRepository->id, $workspace->workspaceName),
                $permissions,
            );
        }
        ksort($workspaces);
        return WorkspaceListing::of(new WorkspaceList(...array_values($workspaces)));
    }
}
