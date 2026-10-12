<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\Workspaces;

use Neos\Api\Endpoint\ContentRepositories\Schema\ContentRepositoryId;
use Neos\Api\Endpoint\Workspaces\RequestBody\WorkspaceCheckout;
use Neos\Api\Endpoint\Workspaces\RequestBody\WorkspaceCreate;
use Neos\Api\Endpoint\Workspaces\RequestBody\WorkspaceUpdate;
use Neos\Api\Endpoint\Workspaces\Response\WorkspaceCreated;
use Neos\Api\Endpoint\Workspaces\Schema\Change;
use Neos\Api\Endpoint\Workspaces\Schema\ChangeList;
use Neos\Api\Endpoint\Workspaces\Schema\Event;
use Neos\Api\Endpoint\Workspaces\Schema\EventList;
use Neos\Api\Endpoint\Workspaces\Schema\PaginatedChangeListing;
use Neos\Api\Endpoint\Workspaces\Schema\PaginatedEventListing;
use Neos\Api\Endpoint\Workspaces\Schema\SequenceNumber;
use Neos\Api\Endpoint\Workspaces\Schema\Workspace;
use Neos\Api\Endpoint\Workspaces\Schema\WorkspaceClassification;
use Neos\Api\Endpoint\Workspaces\Schema\WorkspaceList;
use Neos\Api\Endpoint\Workspaces\Schema\WorkspaceListing;
use Neos\Api\Endpoint\Workspaces\Schema\WorkspaceName;
use Neos\Api\Infrastructure\ContentRepository\ContentRepositoryFinder;
use Neos\Api\Infrastructure\ContentRepository\ContentStreamEventsFactory;
use Neos\Api\Security\AccountPrivileges;
use Neos\Api\Security\ApiAuthContextProvider;
use Neos\Api\Security\ApiCaller;
use Neos\Api\Security\ApiScopes;
use Neos\Api\Shared\Parameter\Limit;
use Neos\Api\Shared\Parameter\Offset;
use Neos\Api\Shared\Response\Conflict;
use Neos\Api\Shared\Response\Forbidden;
use Neos\Api\Shared\Response\NotFound;
use Neos\Api\Shared\Response\UnprocessableContent;
use Neos\Api\Shared\Schema\CursorListingLinks;
use Neos\Api\Shared\Schema\ListingLinks;
use Neos\Api\Shared\Schema\ListingMeta;
use Neos\ContentRepository\Core\Feature\Security\Exception\AccessDenied;
use Neos\ContentRepository\Core\Feature\WorkspaceCommandSkipped;
use Neos\ContentRepository\Core\Feature\WorkspaceCreation\Exception\BaseWorkspaceDoesNotExist;
use Neos\ContentRepository\Core\Feature\WorkspaceCreation\Exception\WorkspaceAlreadyExists;
use Neos\ContentRepository\Core\Feature\WorkspaceModification\Exception\BaseWorkspaceEqualsWorkspaceException;
use Neos\ContentRepository\Core\Feature\WorkspaceModification\Exception\CircularRelationBetweenWorkspacesException;
use Neos\ContentRepository\Core\SharedModel;
use Neos\ContentRepository\Core\SharedModel\Exception\WorkspaceContainsPublishableChanges;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\EventStore\Model\EventEnvelope;
use Neos\Neos\Domain\Model;
use Neos\Neos\Domain\Service\WorkspacePublishingService;
use Neos\Neos\Domain\Service\WorkspaceService;
use Neos\Neos\Fusion\Cache\CacheFlushingStrategy;
use Neos\Neos\Fusion\Cache\ContentCacheFlusher;
use Neos\Neos\Fusion\Cache\FlushWorkspaceRequest;
use Neos\Neos\Security\Authorization\ContentRepositoryAuthorizationService;
use Neos\OpenApi\Attributes\AuthContext;
use Neos\OpenApi\Attributes\Operation;
use Neos\OpenApi\Attributes\Parameter;
use Neos\OpenApi\Attributes\RequestBody;
use Neos\Party\Domain\Service\PartyService;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The workspaces of a content repository, live and the ones changes are made in before they're published, their
 * changes compared to their base workspace and their events, what was changed in them as the event store records it
 */
final readonly class Workspaces
{
    public function __construct(
        private ContentRepositoryFinder $contentRepositoryFinder,
        private ContentRepositoryRegistry $contentRepositoryRegistry,
        private WorkspaceResolver $workspaceResolver,
        private WorkspacePublishingService $workspacePublishingService,
        private WorkspaceService $workspaceService,
        private ContentRepositoryAuthorizationService $contentRepositoryAuthorizationService,
        private AccountPrivileges $accountPrivileges,
        private PartyService $partyService,
        private ContentCacheFlusher $contentCacheFlusher,
    ) {
    }

    #[Operation(
        path: '/cr/{contentRepositoryId}/workspaces',
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

    #[Operation(
        path: '/cr/{contentRepositoryId}/workspaces/{workspaceName}',
        method: 'GET',
        summary: 'Get a workspace',
        description: 'The workspace with its Neos metadata and what the account may do in it, as listWorkspaces lists it. The workspace must be one the account may read, by its workspace roles or as the owner, else it is a 404 as an unknown one.',
        operationId: 'getWorkspace',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::WORKSPACES_READ],
        ],
    )]
    public function get(
        ContentRepositoryId $contentRepositoryId,
        WorkspaceName $workspaceName,
        #[AuthContext] ApiCaller $caller,
    ): Workspace|NotFound {
        $workspace = $this->workspaceResolver->resolve($contentRepositoryId, $workspaceName, $caller->account);
        if ($workspace instanceof NotFound) {
            return $workspace;
        }
        return $this->workspaceOf($workspace);
    }

    #[Operation(
        path: '/cr/{contentRepositoryId}/workspaces',
        method: 'POST',
        summary: 'Create a workspace',
        description: 'Creates a workspace on the base workspace (live if left out, else one the account may read, else a 422) with Neos\' WorkspaceService, as the Neos workspace module does. A shared workspace (the default) needs a title, its name is derived from it if left out, and its visibility decides the role assignments: shared makes the caller its manager and every editor a collaborator, private only the caller its manager. A personal workspace is the caller\'s own, as Neos creates it on the first login: titled and named after the user if left out, without role assignments, and only one per user and content repository, a second one is a 409 that names the first. Root and unknown workspaces can\'t be created, a visibility for a personal workspace, an empty title or an account without a Neos user are 422s, a name that is taken a 409. The response is the new workspace as getWorkspace returns it.',
        operationId: 'createWorkspace',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::WORKSPACES_CREATE],
        ],
    )]
    public function create(
        ContentRepositoryId $contentRepositoryId,
        #[RequestBody(description: 'The workspace')] WorkspaceCreate $newWorkspace,
        #[AuthContext] ApiCaller $caller,
    ): WorkspaceCreated|NotFound|Forbidden|Conflict|UnprocessableContent {
        $classification = $newWorkspace->classification();
        if ($classification !== WorkspaceClassification::SHARED && $classification !== WorkspaceClassification::PERSONAL) {
            return UnprocessableContent::because(sprintf('Only shared and personal workspaces can be created, not %s ones', $classification->value));
        }
        if ($classification === WorkspaceClassification::PERSONAL && $newWorkspace->visibility !== null) {
            return UnprocessableContent::because('A personal workspace has no visibility, only its owner may work in it');
        }
        try {
            $title = $newWorkspace->workspaceTitle();
        } catch (\InvalidArgumentException) {
            return UnprocessableContent::because('The title must be 1 to 200 letters, digits, spaces and punctuation');
        }
        try {
            $description = $newWorkspace->workspaceDescription();
        } catch (\InvalidArgumentException) {
            return UnprocessableContent::because('The description must be at most 500 letters, digits, spaces and punctuation');
        }
        if ($classification === WorkspaceClassification::SHARED && $title === null) {
            return UnprocessableContent::because('A shared workspace needs a title');
        }
        // the creator of a shared workspace manages it, a personal one is the owner's: both need a Neos user
        $user = $this->partyService->getAssignedPartyOfAccount($caller->account);
        if (!$user instanceof Model\User) {
            return UnprocessableContent::because('The account has no Neos user to create the workspace for');
        }

        $contentRepository = $this->contentRepositoryFinder->find($contentRepositoryId->toContentRepositoryId());
        if ($contentRepository === null) {
            return NotFound::because(sprintf('There is no content repository with the ID %s', $contentRepositoryId->value));
        }
        // in the body, not the path: a base the account may not read is unprocessable, as an unknown one
        $baseWorkspaceName = $newWorkspace->baseWorkspaceName();
        if ($this->workspaceResolver->resolve($contentRepositoryId, $baseWorkspaceName, $caller->account) instanceof NotFound) {
            return UnprocessableContent::because(sprintf('There is no workspace %s the account may read in the content repository %s', $baseWorkspaceName->value, $contentRepositoryId->value));
        }
        if ($classification === WorkspaceClassification::PERSONAL) {
            // Neos allows one per user and content repository, found by its metadata
            foreach ($contentRepository->findWorkspaces() as $workspace) {
                $metadata = $this->workspaceService->getWorkspaceMetadata($contentRepository->id, $workspace->workspaceName);
                if ($metadata->classification === Model\WorkspaceClassification::PERSONAL && $metadata->ownerUserId?->equals($user->getId())) {
                    return Conflict::because(sprintf('The user has a personal workspace %s in the content repository %s already', $workspace->workspaceName->value, $contentRepositoryId->value));
                }
            }
            try {
                // as Neos names a user's personal workspace, WorkspaceService::createPersonalWorkspaceForUserIfMissing()
                $title ??= Model\WorkspaceTitle::fromString($user->getLabel());
            } catch (\InvalidArgumentException) {
                return UnprocessableContent::because('The user\'s name can\'t be a workspace title, give one');
            }
        }
        if ($newWorkspace->name !== null) {
            $workspaceName = $newWorkspace->name->toWorkspaceName();
            if ($contentRepository->findWorkspaceByName($workspaceName) !== null) {
                return Conflict::because(sprintf('There is a workspace %s in the content repository %s already', $workspaceName->value, $contentRepositoryId->value));
            }
        } else {
            try {
                $workspaceName = $this->workspaceService->getUniqueWorkspaceName($contentRepository->id, $classification === WorkspaceClassification::PERSONAL ? $user->getLabel() : $title->value);
            } catch (\RuntimeException $exception) {
                // only after 10 taken names
                return Conflict::because($exception->getMessage() . ' Give a name.');
            }
        }

        try {
            if ($classification === WorkspaceClassification::PERSONAL) {
                $this->workspaceService->createPersonalWorkspace($contentRepository->id, $workspaceName, $title, $description, $baseWorkspaceName->toWorkspaceName(), $user->getId());
            } else {
                $this->workspaceService->createSharedWorkspace($contentRepository->id, $workspaceName, $title, $description, $baseWorkspaceName->toWorkspaceName(), $newWorkspace->visibility()->roleAssignmentsFor($user->getId()));
            }
        } catch (AccessDenied) {
            return Forbidden::because(sprintf('You may not create a workspace on the workspace %s', $baseWorkspaceName->value));
        } catch (WorkspaceAlreadyExists) {
            return Conflict::because(sprintf('There is a workspace %s in the content repository %s already', $workspaceName->value, $contentRepositoryId->value));
        } catch (BaseWorkspaceDoesNotExist) {
            return UnprocessableContent::because(sprintf('There is no workspace %s the account may read in the content repository %s', $baseWorkspaceName->value, $contentRepositoryId->value));
        } catch (\RuntimeException $exception) {
            // the user's personal workspace is gone, its metadata not: WorkspaceService still counts it
            if ($exception->getCode() === 1733754904) {
                return Conflict::because($exception->getMessage());
            }
            throw $exception;
        }

        $created = $this->workspaceResolver->resolve($contentRepositoryId, WorkspaceName::fromString($workspaceName->value), $caller->account);
        if ($created instanceof NotFound) {
            // can't happen: its manager or owner may read it
            throw new \RuntimeException(sprintf('The workspace %s was created, but the account may not read it', $workspaceName->value), 1760280001);
        }
        return new WorkspaceCreated($this->workspaceOf($created));
    }

    #[Operation(
        path: '/cr/{contentRepositoryId}/workspaces/{workspaceName}',
        method: 'PATCH',
        summary: 'Change a workspace',
        description: 'Changes the given Neos metadata of the workspace, its title and description, and leaves the others as they are. The title is trimmed and must not be empty, an empty description removes it. The base workspace is changed with checkoutWorkspace. The workspace must be one the account may read (else it is a 404 as an unknown one) and manage, as its owner, a manager or a Neos administrator (else a 403). Root workspaces like live can\'t be changed, as in Neos\' workspace module: a 422, whoever asks.',
        operationId: 'updateWorkspace',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::WORKSPACES_UPDATE],
        ],
    )]
    public function update(
        ContentRepositoryId $contentRepositoryId,
        WorkspaceName $workspaceName,
        #[AuthContext] ApiCaller $caller,
        #[RequestBody(description: 'The metadata to change')] WorkspaceUpdate $patch,
    ): Workspace|NotFound|Forbidden|UnprocessableContent {
        $workspace = $this->workspaceResolver->resolve($contentRepositoryId, $workspaceName, $caller->account);
        if ($workspace instanceof NotFound) {
            return $workspace;
        }
        // never changeable, whoever asks: Neos' workspace module offers no editing for them (WorkspaceController::editAction())
        if ($workspace->workspace->isRootWorkspace()) {
            return UnprocessableContent::because(sprintf('The workspace %s is a root workspace, which can\'t be changed', $workspaceName->value));
        }
        if (!$workspace->permissions->manage) {
            return Forbidden::because(sprintf('You may not manage the workspace %s', $workspaceName->value));
        }
        try {
            $title = $patch->newTitle();
        } catch (\InvalidArgumentException) {
            return UnprocessableContent::because('The title must be 1 to 200 letters, digits, spaces and punctuation characters');
        }
        try {
            $description = $patch->newDescription();
        } catch (\InvalidArgumentException) {
            return UnprocessableContent::because('The description must be at most 500 letters, digits, spaces and punctuation characters');
        }
        $crId = $workspace->contentRepository->id;
        $name = $workspace->workspace->workspaceName;
        $metadata = $this->workspaceService->getWorkspaceMetadata($crId, $name);
        try {
            // only what changes
            if ($title !== null && !$title->equals($metadata->title)) {
                $this->workspaceService->setWorkspaceTitle($crId, $name, $title);
            }
            if ($description !== null && !$description->equals($metadata->description)) {
                $this->workspaceService->setWorkspaceDescription($crId, $name, $description);
            }
        } catch (AccessDenied) {
            // the service checks Flow's security context, which may know other roles than the token's account
            return Forbidden::because(sprintf('You may not manage the workspace %s', $workspaceName->value));
        }
        return $this->workspaceOf($workspace);
    }

    #[Operation(
        path: '/cr/{contentRepositoryId}/workspaces/{workspaceName}',
        method: 'DELETE',
        summary: 'Delete a workspace',
        description: 'Deletes the workspace with its Neos metadata and role assignments, as the Neos workspace module does. The workspace must be one the account may read (else a 404 as an unknown one) and manage, by its workspace roles, as the owner or as a Neos administrator (else a 403). Root workspaces like live can\'t be deleted, personal workspaces only with their user: both are a 422. A workspace other workspaces are based on is a 409 that names them, they have to be deleted or based on another workspace first. A workspace with unpublished changes is a 409 as well, unless force is true: then the changes are discarded with the workspace.',
        operationId: 'deleteWorkspace',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::WORKSPACES_DELETE],
        ],
    )]
    public function delete(
        ContentRepositoryId $contentRepositoryId,
        WorkspaceName $workspaceName,
        #[AuthContext] ApiCaller $caller,
        #[Parameter(in: 'query', description: 'Whether to delete the workspace with its unpublished changes, which are lost then. false if omitted: a workspace with changes is a 409')]
        bool $force = false,
    ): NotFound|Forbidden|UnprocessableContent|Conflict|null {
        $workspace = $this->workspaceResolver->resolve($contentRepositoryId, $workspaceName, $caller->account);
        if ($workspace instanceof NotFound) {
            return $workspace;
        }
        $contentRepositoryId = $workspace->contentRepository->id;
        $workspaceName = $workspace->workspace->workspaceName;
        // never deletable, whoever asks
        $classification = $this->workspaceService->getWorkspaceMetadata($contentRepositoryId, $workspaceName)->classification;
        if ($workspace->workspace->isRootWorkspace() || $classification === Model\WorkspaceClassification::ROOT) {
            return UnprocessableContent::because(sprintf('The workspace %s is a root workspace, which can\'t be deleted', $workspaceName->value));
        }
        if ($classification === Model\WorkspaceClassification::PERSONAL) {
            return UnprocessableContent::because(sprintf('The workspace %s is a personal workspace, which is deleted with its user only', $workspaceName->value));
        }
        if (!$workspace->permissions->manage) {
            return Forbidden::because(sprintf('You may not manage the workspace %s', $workspaceName->value));
        }
        $dependants = $workspace->contentRepository->findWorkspaces()->getDependantWorkspaces($workspaceName);
        if (!$dependants->isEmpty()) {
            return Conflict::because(sprintf('The workspace %s can\'t be deleted, other workspaces are based on it: %s', $workspaceName->value, $this->namesOf($contentRepositoryId, $dependants, $caller)));
        }
        // the content repository's own check before publishing or discarding, the count of the changes is @internal
        if ($workspace->workspace->hasPublishableChanges() && !$force) {
            return Conflict::because(sprintf('The workspace %s has unpublished changes, publish or discard them first, or delete it with force=true to discard them with it', $workspaceName->value));
        }
        // removes the content stream (and with it the changes), the metadata and the role assignments
        $this->workspaceService->deleteWorkspace($contentRepositoryId, $workspaceName);
        return null;
    }

    /**
     * The names of the workspaces the account may read, the others only counted, so they can't be probed
     */
    private function namesOf(SharedModel\ContentRepository\ContentRepositoryId $contentRepositoryId, SharedModel\Workspace\Workspaces $workspaces, ApiCaller $caller): string
    {
        $roles = $this->accountPrivileges->rolesOf($caller->account);
        $user = $this->partyService->getAssignedPartyOfAccount($caller->account);
        $userId = $user instanceof Model\User ? $user->getId() : null;
        $names = [];
        $others = 0;
        foreach ($workspaces as $workspace) {
            if ($this->contentRepositoryAuthorizationService->getWorkspacePermissions($contentRepositoryId, $workspace->workspaceName, $roles, $userId)->read) {
                $names[] = $workspace->workspaceName->value;
            } else {
                $others++;
            }
        }
        sort($names);
        if ($others > 0) {
            $names[] = sprintf('%d other%s the account may not read', $others, $others === 1 ? '' : 's');
        }
        return implode(', ', $names);
    }

    #[Operation(
        path: '/cr/{contentRepositoryId}/workspaces/{workspaceName}/checkout',
        method: 'POST',
        summary: 'Change the base of a workspace',
        description: 'Bases the workspace on another workspace from now on (ChangeBaseWorkspace), like checking out another workspace: its content is replaced as a whole by the new base\'s current content, and its changes will be published there. Only a workspace without publishable changes can be checked out, else it is a 409: publish or discard them first. The workspace must be one the account may read (else a 404, as an unknown one) and manage (else a 403). The new base must exist and be readable by the account, and can\'t be the workspace itself or one based on it, else it is a 422, as is checking out a root workspace like live, which has no base. The current base sends no command. The response is the workspace as getWorkspace returns it, with its new base.',
        operationId: 'checkoutWorkspace',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::WORKSPACES_UPDATE],
        ],
    )]
    public function checkout(
        ContentRepositoryId $contentRepositoryId,
        WorkspaceName $workspaceName,
        #[RequestBody(description: 'The new base workspace')] WorkspaceCheckout $checkout,
        #[AuthContext] ApiCaller $caller,
    ): Workspace|NotFound|Forbidden|Conflict|UnprocessableContent {
        $workspace = $this->workspaceResolver->resolve($contentRepositoryId, $workspaceName, $caller->account);
        if ($workspace instanceof NotFound) {
            return $workspace;
        }
        // never, whoever asks
        if ($workspace->workspace->baseWorkspaceName === null) {
            return UnprocessableContent::because(sprintf('The workspace %s is a root workspace, it has no base to change', $workspaceName->value));
        }
        if (!$workspace->permissions->manage) {
            return Forbidden::because(sprintf('You may not manage the workspace %s', $workspaceName->value));
        }
        // the body names it, so it's a 422, not the 404 of the path
        if ($this->workspaceResolver->resolve($contentRepositoryId, $checkout->baseWorkspaceName, $caller->account) instanceof NotFound) {
            return UnprocessableContent::because(sprintf('There is no workspace %s in the content repository %s to base the workspace on', $checkout->baseWorkspaceName->value, $contentRepositoryId->value));
        }
        // the content repository would skip the command
        if ($workspace->workspace->baseWorkspaceName->equals($checkout->baseWorkspaceName->toWorkspaceName())) {
            return $this->workspaceOf($workspace);
        }
        try {
            $this->workspacePublishingService->changeBaseWorkspace($workspace->contentRepository->id, $workspace->workspace->workspaceName, $checkout->baseWorkspaceName->toWorkspaceName());
        } catch (WorkspaceCommandSkipped) {
            // the base was changed to it meanwhile
            return $this->workspaceOf($workspace);
        } catch (AccessDenied) {
            return Forbidden::because(sprintf('You may not change the base of the workspace %s to %s', $workspaceName->value, $checkout->baseWorkspaceName->value));
        } catch (WorkspaceContainsPublishableChanges) {
            return Conflict::because(sprintf('The workspace %s has publishable changes, publish or discard them before changing its base', $workspaceName->value));
        } catch (BaseWorkspaceEqualsWorkspaceException) {
            return UnprocessableContent::because(sprintf('The workspace %s can\'t be based on itself', $workspaceName->value));
        } catch (CircularRelationBetweenWorkspacesException) {
            return UnprocessableContent::because(sprintf('The workspace %s can\'t be based on %s, which is based on it', $workspaceName->value, $checkout->baseWorkspaceName->value));
        }
        // Neos' cache flushing hook doesn't handle WorkspaceBaseWorkspaceWasChanged, the content of the old base would be rendered
        $this->contentCacheFlusher->flushWorkspace(
            FlushWorkspaceRequest::create($workspace->contentRepository->id, $workspace->workspace->workspaceName),
            CacheFlushingStrategy::IMMEDIATE,
        );
        return $this->workspaceOf($workspace);
    }

    #[Operation(
        path: '/cr/{contentRepositoryId}/workspaces/{workspaceName}/changes',
        method: 'GET',
        summary: 'List the changes of a workspace',
        description: 'What changed in the workspace compared to its base workspace and isn\'t published yet, one item per node variant (or per node aggregate for changes to all of its variants, like its name or node type), as Neos\' pending changes projection keeps it, sorted by aggregate id and dimension space point. The workspace must be one the account may read, by its workspace roles or as the owner. Changes of a node the account may not read are left out. Changes of a node that no longer exists in the workspace are listed: it has no permissions left to check. offset and limit (25 by default, 100 at most) choose the page, meta.total and links tell about the others.',
        operationId: 'listWorkspaceChanges',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::WORKSPACES_READ],
        ],
    )]
    public function listChanges(
        ServerRequestInterface $request,
        ContentRepositoryId $contentRepositoryId,
        WorkspaceName $workspaceName,
        #[AuthContext] ApiCaller $caller,
        #[Parameter(in: 'query', description: 'How many items to skip, 0 if omitted')]
        Offset|null $offset = null,
        #[Parameter(in: 'query', description: 'How many items at most, 25 if omitted, 100 at most')]
        Limit|null $limit = null,
    ): PaginatedChangeListing|NotFound {
        $offset ??= Offset::none();
        $limit ??= Limit::default();
        $workspace = $this->workspaceResolver->resolve($contentRepositoryId, $workspaceName, $caller->account);
        if ($workspace instanceof NotFound) {
            return $workspace;
        }
        $changes = [];
        // the projection reads them all at once and can't check permissions, so they're filtered and paged here
        foreach ($this->workspacePublishingService->pendingWorkspaceChanges($workspace->contentRepository->id, $workspace->workspace->workspaceName) as $change) {
            if ($workspace->mayReadNodeAggregate($change->nodeAggregateId)) {
                $changes[] = Change::from($change);
            }
        }
        // the projection has no order
        usort($changes, static fn (Change $a, Change $b) => [$a->nodeAggregateId->value, $a->originDimensionSpacePoint?->value] <=> [$b->nodeAggregateId->value, $b->originDimensionSpacePoint?->value]);
        return new PaginatedChangeListing(
            new ChangeList(...array_slice($changes, $offset->value, $limit->value)),
            new ListingMeta(count($changes)),
            ListingLinks::for($request, $offset, $limit, count($changes)),
        );
    }

    #[Operation(
        path: '/cr/{contentRepositoryId}/workspaces/{workspaceName}/events',
        method: 'GET',
        summary: 'List the events of a workspace',
        description: 'The events of the workspace\'s current content stream, oldest first: what was changed in it since it was created or last published, discarded or rebased, which each start a new content stream. The workspace must be one the account may read, by its workspace roles or as the owner. Events about a node the account may not read are left out. Events about a node that no longer exists in the workspace, e.g. its removal, are listed: it has no permissions left to check. after and limit (25 by default, 100 at most) choose the page, links.next continues after it.',
        operationId: 'listWorkspaceEvents',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::WORKSPACES_READ],
        ],
    )]
    public function listEvents(
        ServerRequestInterface $request,
        ContentRepositoryId $contentRepositoryId,
        WorkspaceName $workspaceName,
        #[AuthContext] ApiCaller $caller,
        #[Parameter(in: 'query', description: 'The sequenceNumber of the last event of the page before, the first page if omitted')]
        SequenceNumber|null $after = null,
        #[Parameter(in: 'query', description: 'How many items at most, 25 if omitted, 100 at most')]
        Limit|null $limit = null,
    ): PaginatedEventListing|NotFound {
        $limit ??= Limit::default();
        $workspace = $this->workspaceResolver->resolve($contentRepositoryId, $workspaceName, $caller->account);
        if ($workspace instanceof NotFound) {
            return $workspace;
        }
        $stream = $this->contentRepositoryRegistry
            ->buildService($workspace->contentRepository->id, new ContentStreamEventsFactory())
            ->load($workspace->workspace->currentContentStreamId);

        $events = [];
        $from = $after !== null ? $after->value + 1 : 1;
        // in batches: the event store reads all events of a query at once, and left out events don't count
        do {
            $batch = iterator_to_array($stream->withMinimumSequenceNumber(SequenceNumber::fromInteger($from)->toSequenceNumber())->limit($limit->value + 1), false);
            foreach ($batch as $envelope) {
                $from = $envelope->sequenceNumber->value + 1;
                if (!self::isAboutReadableNode($envelope, $workspace)) {
                    continue;
                }
                // one more than the page, so there is a next one
                if (count($events) === $limit->value) {
                    return self::eventListing($request, $limit, $events, true);
                }
                $events[] = Event::from($envelope);
            }
        } while (count($batch) === $limit->value + 1);
        return self::eventListing($request, $limit, $events, false);
    }

    /**
     * The workspace as it is now (re-read, so it reflects a change made in this request), with its metadata and the
     * account's permissions
     */
    private function workspaceOf(ReadableWorkspace $workspace): Workspace
    {
        $contentRepositoryId = $workspace->contentRepository->id;
        $workspaceName = $workspace->workspace->workspaceName;
        return Workspace::from(
            $workspace->contentRepository->findWorkspaceByName($workspaceName) ?? $workspace->workspace,
            $this->workspaceService->getWorkspaceMetadata($contentRepositoryId, $workspaceName),
            $workspace->permissions,
        );
    }

    /**
     * Whether the event isn't about a node (but e.g. its content stream) or about one the account may read
     */
    private static function isAboutReadableNode(EventEnvelope $envelope, ReadableWorkspace $workspace): bool
    {
        $payload = json_decode($envelope->event->data->value, true, flags: JSON_THROW_ON_ERROR);
        $nodeAggregateId = is_array($payload) ? ($payload['nodeAggregateId'] ?? null) : null;
        return !is_string($nodeAggregateId) || $workspace->mayReadNodeAggregate(SharedModel\Node\NodeAggregateId::fromString($nodeAggregateId));
    }

    /**
     * @param list<Event> $events
     */
    private static function eventListing(ServerRequestInterface $request, Limit $limit, array $events, bool $hasMore): PaginatedEventListing
    {
        $last = end($events);
        return new PaginatedEventListing(
            new EventList(...$events),
            CursorListingLinks::for($request, $limit, $hasMore && $last !== false ? $last->sequenceNumber->value : null),
        );
    }
}
