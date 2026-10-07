<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\Workspaces;

use Neos\Api\Endpoint\ContentRepositories\Schema\ContentRepositoryId;
use Neos\Api\Endpoint\Workspaces\Schema\WorkspaceName;
use Neos\Api\Infrastructure\ContentRepository\ContentRepositoryFinder;
use Neos\Api\Security\AccountPrivileges;
use Neos\Api\Shared\Response\NotFound;
use Neos\ContentRepository\Core\Feature\Security\Exception\AccessDenied;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Security\Account;
use Neos\Neos\Domain\Model;
use Neos\Neos\Security\Authorization\ContentRepositoryAuthorizationService;
use Neos\Party\Domain\Service\PartyService;

/**
 * The content repository and workspace of a request → the workspace as the token's account may read it, or the 404
 */
#[Flow\Scope('singleton')]
final readonly class WorkspaceResolver
{
    public function __construct(
        private ContentRepositoryFinder $contentRepositoryFinder,
        private ContentRepositoryAuthorizationService $contentRepositoryAuthorizationService,
        private AccountPrivileges $accountPrivileges,
        private PartyService $partyService,
    ) {
    }

    /**
     * The same 404 for a workspace the account may not read as for an unknown one, so workspaces can't be probed
     */
    public function resolve(ContentRepositoryId $contentRepositoryId, WorkspaceName $workspaceName, Account $account): ReadableWorkspace|NotFound
    {
        $contentRepository = $this->contentRepositoryFinder->find($contentRepositoryId->toContentRepositoryId());
        if ($contentRepository === null) {
            return NotFound::because(sprintf('There is no content repository with the ID %s', $contentRepositoryId->value));
        }
        $notFound = NotFound::because(sprintf('There is no workspace %s in the content repository %s', $workspaceName->value, $contentRepositoryId->value));
        $workspace = $contentRepository->findWorkspaceByName($workspaceName->toWorkspaceName());
        if ($workspace === null) {
            return $notFound;
        }
        // the token's account alone, as for its privileges, not Flow's security context
        $roles = $this->accountPrivileges->rolesOf($account);
        $user = $this->partyService->getAssignedPartyOfAccount($account);
        if (!$this->contentRepositoryAuthorizationService->getWorkspacePermissions($contentRepository->id, $workspace->workspaceName, $roles, $user instanceof Model\User ? $user->getId() : null)->read) {
            return $notFound;
        }
        try {
            $contentGraph = $contentRepository->getContentGraph($workspace->workspaceName);
        } catch (AccessDenied) {
            return $notFound;
        }
        return new ReadableWorkspace($contentRepository, $workspace, $contentGraph, $roles, $this->contentRepositoryAuthorizationService);
    }
}
