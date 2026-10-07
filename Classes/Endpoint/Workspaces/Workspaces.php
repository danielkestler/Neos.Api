<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\Workspaces;

use Neos\Api\Endpoint\ContentRepositories\Schema\ContentRepositoryId;
use Neos\Api\Endpoint\Workspaces\Schema\Change;
use Neos\Api\Endpoint\Workspaces\Schema\ChangeList;
use Neos\Api\Endpoint\Workspaces\Schema\Event;
use Neos\Api\Endpoint\Workspaces\Schema\EventList;
use Neos\Api\Endpoint\Workspaces\Schema\PaginatedChangeListing;
use Neos\Api\Endpoint\Workspaces\Schema\PaginatedEventListing;
use Neos\Api\Endpoint\Workspaces\Schema\SequenceNumber;
use Neos\Api\Endpoint\Workspaces\Schema\Workspace;
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
use Neos\Api\Shared\Response\NotFound;
use Neos\Api\Shared\Schema\CursorListingLinks;
use Neos\Api\Shared\Schema\ListingLinks;
use Neos\Api\Shared\Schema\ListingMeta;
use Neos\ContentRepository\Core\SharedModel;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\EventStore\Model\EventEnvelope;
use Neos\Neos\Domain\Model;
use Neos\Neos\Domain\Service\WorkspacePublishingService;
use Neos\Neos\Domain\Service\WorkspaceService;
use Neos\Neos\Security\Authorization\ContentRepositoryAuthorizationService;
use Neos\OpenApi\Attributes\AuthContext;
use Neos\OpenApi\Attributes\Operation;
use Neos\OpenApi\Attributes\Parameter;
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
