# Workspaces

`Endpoint/Workspaces`: `/cr/{contentRepositoryId}/workspaces/…`. The workspaces themselves, their role assignments,
publishing, the pending changes and the event stream. `Workspaces\Schema\WorkspaceName` is the `workspaceName`
parameter of Nodes and Views as well.

## Reading and permissions

- `WorkspaceResolver::resolve()`: content repository + workspace → `ReadableWorkspace` or the 404, the same 404 for
  an unreadable workspace as for an unknown one, so workspaces can't be probed. Administrators without a role get the
  404 as well: Neos lets them manage every workspace but read only the ones a role grants them.
- `WorkspaceResolver::permissionsOf()` and `userOf()` are the only way to the token account's workspace permissions
  (`ContentRepositoryAuthorizationService::getWorkspacePermissions()` with `AccountPrivileges::rolesOf()`) and its Neos
  user. Never Flow's security context. Neos' services check Flow's context themselves; their `AccessDenied` is mapped
  to a fallback 403.
- `ReadableWorkspace`: the workspace, the account's permissions (`read`, `write`, `manage`), and
  `mayReadNodeAggregate()`: one readable node of the aggregate is enough (what happened to an aggregate is about all
  of its nodes); an aggregate that no longer exists in the workspace has no permissions left, so whoever may read the
  workspace may read it. `contentGraph(base: true)` is the base's graph, looked up once per request; an unreadable
  base counts as no base. `refreshed()` re-reads the workspace after a publish, discard or rebase in the same request
  (each gives it a new content stream its old graphs don't see).
- Root check everywhere: `Workspace::isRootWorkspace()`. Neos' metadata only for "personal".

## Changing workspaces

- Always through Neos' `WorkspaceService` / `WorkspacePublishingService`, never bare CR commands, so metadata and role
  assignments stay right. Their request bodies have no `applyTo()`: there is no model.
- Order of checks: path (404), then what nobody may do (root workspaces, deleting personal ones, roles on personal
  ones: 422, "whoever asks"), then the permission (`manage` or `write`, 403). A workspace named in the body (a base)
  that is unknown or unreadable is a 422, not a 404.
- Scopes `workspaces.create`/`update`/`delete`/`publish`/`roles` are granted to `Neos.Neos:AbstractEditor` as Neos
  grants creating workspaces (`Neos.Workspace.Ui:Backend.CreateWorkspaces`); which workspaces is up to the roles.
- Title and description follow Neos' `WorkspaceTitle` (trimmed, 1–200 letters, digits, spaces, punctuation) and
  `WorkspaceDescription` (up to 500), else a 422.

### createWorkspace

- An unknown content repository is a 404 before any body 422. An account without a Neos user is a 422: the creator
  manages a shared workspace and owns a personal one.
- Shared (default): `visibility` `public` (default, `WorkspaceRoleAssignments::createForSharedWorkspace`: creator
  manager, `Neos.Neos:AbstractEditor` collaborator) or `private` (`createForPrivateWorkspace`: creator manager only).
  `shared` is only a classification, not a visibility. The name is `getUniqueWorkspaceName(title)` unless given.
- Personal: created explicitly only here, deliberately (no auto-provisioning in a GET, nothing under `/me`). Title and
  name follow `createPersonalWorkspaceForUserIfMissing` (the user's label), no role assignments, a `visibility` is a
  422. Neos allows one per user and CR: an existing one (`WorkspaceService::getPersonalWorkspaceForUser()`) is a 409
  naming it, as is `createPersonalWorkspace`'s `RuntimeException` 1733754904 (metadata of a deleted one left).

### updateWorkspace, deleteWorkspace

- `updateWorkspace` sets only what differs, via `setWorkspaceTitle()`/`setWorkspaceDescription()`. Unknown fields like
  `baseWorkspaceName` are a 400 (that's `changeBaseWorkspace`). Root workspaces are a 422: Neos' workspace module
  offers no editing for them (`WorkspaceController::editAction()`), though `WorkspaceService` would allow it.
- `deleteWorkspace` calls `WorkspaceService::deleteWorkspace()` (content stream, metadata, role assignments, then the
  soft removal garbage collector). Workspaces based on it (`getDependantWorkspaces()`; the CR doesn't check) are a 409
  naming the readable ones and counting the others. Unpublished changes (`Workspace::hasPublishableChanges()`, `@api`,
  not the `@internal` count) are a 409 unless `?force=true`. `AccessDenied` is a fallback 403.

### changeBaseWorkspace

`PUT …/baseworkspace`, a sub-resource like `changeNodeType`, not a PATCH field: the CR forks a new content stream from
the new base, replacing the content as a whole, and only without publishable changes
(`WorkspaceContainsPublishableChanges`, 409). `BaseWorkspaceEqualsWorkspaceException` and
`CircularRelationBetweenWorkspacesException` are 422s. The current base sends no command (the CR would skip it with
`WorkspaceCommandSkipped`, before checking changes). Neos' cache flushing hook ignores
`WorkspaceBaseWorkspaceWasChanged`, so the endpoint flushes the workspace's content cache itself
(`ContentCacheFlusher::flushWorkspace()`, `IMMEDIATE`; public, neither `@api` nor `@internal`).

## Roles

- `PUT`/`DELETE …/roles/{subjectType}/{subjectId}`: keyed by subject, not a POST. `subjectType` `user` (Neos user ID)
  or `group` (Flow role identifier); roles `viewer|collaborator|manager`, lowercased Neos values.
  `WorkspaceRoleSubjects` maps type and ID to Neos' `WorkspaceRoleSubject`.
- Listing needs `manage` (403), as in Neos' workspace module: the assignments show who works on what.
- Assigning is idempotent: Neos has one role per subject, so a different role is unassigned, then assigned. Not
  atomic: if the assign fails the previous role is given back, and the 409's detail says so if that fails too. A
  concurrent change (Neos' codes 1728476154, 1728477071) is a 409. It returns 200 with the assignment, also when new:
  there is no GET of a single assignment for a `Location`.
- Unknown user (`UserService::findUserById`) or Flow role (`PolicyService::hasRole`, abstract roles included, Neos
  assigns them itself) is a 422 on assign. `DELETE` doesn't check that they still exist, so leftovers can be removed.
- Personal workspaces are a 422: `WorkspaceService` would allow it, the module offers no roles for them. As in the
  module, your own user assignment can't be lowered or removed (422), so a manager can't lock themselves out. Root
  workspaces like live are allowed for whoever may manage them.

## Publishing, discarding, rebasing

- Actions (`POST …/publish`, `…/discard`, `…/rebase`), scope `workspaces.publish`, through
  `WorkspacePublishingService`. Publish and discard share `publishOrDiscard()`.
- The body is optional (object default): none or `{}` is everything, `siteAggregateId` the site's changes
  (`…ChangesInSite`), `documentAggregateIds` each document's own changes, its content but not its child documents
  (`…ChangesInDocument`). The CR has no command for several documents, so they go one by one, ordered by
  `DocumentAncestry`: ancestors first for publishing (a new page needs its new parent in the base), descendants first
  for discarding. A conflict on the Nth leaves the earlier ones done, as in Neos' workspace module.
- Selected sites and documents must be readable nodes of `Neos.Neos:Site` / `Neos.Neos:Document` in the workspace
  (422, checked before anything is sent). Removed nodes are still there (only tagged), so their removal can be
  published. One removed meanwhile by another request (`NodeAggregateCurrentlyDoesNotExist`) is a 409 titled
  `removed`.
- Publishing also needs write on the base with the token's account; Neos' auth provider checks the same with Flow's
  context, its `AccessDenied` is the fallback 403.
- `Response\WorkspaceConflict` (409) carries `WorkspaceConflictProblem {type, title: rebase|partial|removed, status,
  detail, conflicts, inaccessibleConflicts, completedDocumentAggregateIds, numberOfCompletedChanges}`.
  `WorkspaceRebaseFailed` is `rebase`, `PartialWorkspaceRebaseFailed` (the selection can't be separated from the other
  changes) `partial`. One `ConflictingChange {nodeAggregateId, documentAggregateId, siteAggregateId, reason, message}`
  per node, located by `ChangeLocator`; conflicts about nodes the account can't read are left out and counted. Partial
  success is machine-readable.
- `WorkspaceCommandSkipped` (nothing to do) counts 0. The counts are Neos' (pending change rows for everything, node
  aggregates for a site or document, summed over documents). A soft removal in the base doesn't conflict with
  property changes: the node is only tagged.
- Rebase: `Schema\RebaseStrategy` `fail` (default) or `force`, the CR's `RebaseErrorHandlingStrategy`. `force` drops
  the conflicting changes for good (the event's `skippedEvents`, not reported; the changes list shows what's left). An
  up-to-date workspace is a 200: with `fail` the CR skips it, with `force` it forks again. A node created with an ID
  the base has meanwhile conflicts with `reason` null. Neos' cache flushing hook handles `WorkspaceWasRebased`.

## Changes (`listWorkspaceChanges`, `ChangeQuery`, `ChangeLocator`, `ChangeDiffer`)

- Source: `WorkspacePublishingService::pendingWorkspaceChanges()`, an `@api` service but an experimental method; the
  projection (`Neos\Neos\PendingChangesProjection`) is `@internal` and due to be refactored
  (neos-development-collection#5493). Only `Schema\Change::from()` and `ChangeDiffer` touch its types.
- The projection reads all rows at once, has no order and can't filter or check permissions, so `ChangeQuery` does it
  in PHP: unreadable nodes out, locate, filter, sort by document → node → dimension space point (aggregate-wide
  changes first, changes without a document last), page. `meta.total` counts the filtered changes. `Change::from()`
  runs once per item, `withDiff()` only for the page.
- `ChangeLocator` (per request, cached) finds the closest document and site in the change's origin point (for an
  aggregate-wide change in one the aggregate covers), without visibility constraints so hidden and removed nodes
  resolve, in the base workspace for a node removed for good. Documents and sites are looked up for every change only
  when filtering by them.
- `filterByChangeType=deleted` is the workspace's trash (Neos' own trash projection is `@internal`); `restoreNode`
  restores from it.
- `include=diff` (`ChangeDiffer`): the net difference between the node in the workspace and in its base, what
  publishing would apply. State, not events: a value changed and changed back is no difference. Both read in the
  change's point without visibility constraints; in the base that may be another variant shining through (a new
  `en_UK` specialization is compared to its `en_US` fallback). No base (root, or unreadable): compared to nothing.
  `createdVariant`: the projection can't tell a new dimension variant from a new node. Properties compared serialized
  and returned as `Node.properties`; references by target and order (not their properties, target permissions not
  checked, as for `updateNodeReferences`); `parentAggregateId` only for a new parent (a move among siblings is `moved`
  without it); `tags` the node's own, without `removed` (that's `deleted`). Deleted changes get an empty diff (a node
  created and removed would list all its properties). Aggregate-wide changes fill only `nodeType`/`name`.

## Events (`listWorkspaceEvents`, `EventQuery`)

- The events of the workspace's current content stream as the event store keeps them. The CR doesn't expose its
  event store: `Infrastructure/ContentRepository/ContentStreamEvents` gets it as a CR service
  (`ContentStreamEventsFactory`); both it and `ContentStreamEventStreamName` are `@internal` in Neos 9, so nothing
  else touches them. Payloads are decoded once (`JsonValues`).
- Filtering: events about an unreadable node are left out (`mayReadNodeAggregate()`), and in the listed ones every
  string under a payload key ending in `nodeAggregateId(s)`, at any depth, is null if the account may not read it.
- Cursor paging: the event store has no offset and no count, and sequence numbers have gaps (other streams,
  pruning). `after` is the last event of the page before in the listing's order (newest first reads with
  `EventStream::backwards()` and a maximum sequence number). The Doctrine event store reads all rows of a query at
  once, so `EventQuery` reads in batches of `limit + 1` until the page is full. Oldest first,
  `CursorListingLinks::forGrowingList()` always sets `links.next` (polling); newest first, `for()` ends with null.
- A new content stream's sequence numbers are all higher than the old one's. `ContentStreamEvents::findFirst()` gives
  the current stream's first event: an `after` lower than its sequence number is from before a publish, discard or
  rebase and is a 409 ("start over without after"). `meta {contentStreamId, forkedFrom}` stays for information
  (`forkOf()` reads the fork from that first event, null for live's).
- The events are an operation of `Workspaces`, not an endpoint class of their own: the FlowAdapter names the tag after
  the class, and they belong under that tag.

## Tests

`WorkspacesTest` (`EndpointTestCase`, no CR setup) and `WorkspacesContentRepositoryTest` (real CR, `Testing/Ddev`: live with a
Neos.Demo homepage and a page, changes sent as CR commands without authorization checks).
