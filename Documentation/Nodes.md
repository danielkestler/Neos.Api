# Nodes

`Endpoint/Nodes`: the nodes of the content repositories, read directly, without Fusion (Fusion is `Views.md`). What
each operation does is in its `description:`; this is the why behind it.

## Addressing a node

There are no node addresses in the API. A node is its content repository (path), its `nodeAggregateId` (path) and the
query parameters `workspaceName` and `dimensionSpacePoint`, named like the `Node` fields, so a client copies them over.

- `workspaceName` is `live` by default for reads; writes require it (changing live directly is rarely what is meant).
- `dimensionSpacePoint` is a JSON string (`{"language":"de"}`). A deepObject map would need map support in
  neos/schematic, which builds objects from named constructor parameters only. Its default is the
  `defaultDimensionSpacePoint` of the content repository's default site, else the only point of a CR without a site
  (`DimensionSpacePointFinder`), else a 400.
- `SubgraphResolver::resolve()` turns content repository, workspace and point into the subgraph, with the errors in a
  fixed order: point syntax (400) before any lookup, unknown CR (404, `NotFound::contentRepository()`), no default
  point or a point not in the CR (400), unknown or unreadable workspace (404). `resolveNode()` adds `resolveNodeIn()`,
  the one "no node" 404. Views and DataSources use the same class, so their errors are the same.
- `SiteFinder::findDefault()`: the configured default site if it is in the CR, else the CR's first online site by
  name. A configured default site that is missing or offline is skipped as if none were configured.

## Visibility

`ContentSubgraphs` reads with the token account's roles (`AccountPrivileges::rolesOf()`) and its Neos user: workspace
read permission from `ContentRepositoryAuthorizationService::getWorkspacePermissions()`, visibility constraints from
`getVisibilityConstraints()`. Not Flow's security context, which may hold other tokens' roles; the CR's own check in
`getContentGraph()` stays as a fallback. A node carries its visibility, `ContentRepositoryRegistry::subgraphForNode()`
is the subgraph it was found in. Hidden nodes are visible to accounts that may see them in every workspace, live
included, as in the Neos backend (`excludeDisabled: false`); only `renderView` in the frontend mode in live leaves
them out.

`findVariants(Node)` reads the other variants with the node's visibility; `findAllVariants(ContentRepository,
WorkspaceName, id, excludeDisabled)` is every occupied point (`listNodeVariants`, null for an unreadable workspace).

## Reading

- `Node` fields: what the node is (`nodeAggregateId`, `name`, `nodeType`, `label` as plain text, `classification`),
  where it was read (`contentRepositoryId`, `workspaceName`, `dimensionSpacePoint`), state (`isHidden`,
  `isHiddenByAncestor`, `isShineThrough`, `tags` set on itself, `inheritedTags`, `timestamps`), `properties`, and the
  includes. `NodeSerializer` provides label, state, timestamps, properties (serialized as stored, assets as
  `{id, url}`) and references (`{name: [{nodeAggregateId, properties}]}`), the same for `Node` and for Fusion
  (`Neos.Api.Node`).
- Include paths of `getNode`/`listNodes`: `references`, `children`, `children.references`, `variants`,
  `variants.references`, `ancestors` (nearest first, root left out). `listNodeVariants` takes only
  `VARIANT_INCLUDE_PATHS` (`references`, `children`, `children.references`): variants have no variants, and their
  ancestors are the node's.
- `include=variants` are the others of the node read in one point; `GET …/variants` (`listNodeVariants`) is all of
  them, each read in its own point, workspace resolved by `Workspaces\Domain\WorkspaceResolver`. The other
  sub-resources (`/properties`, `/references`, `/tags`, `/nodetype`) have no GET: they are fields of `Node`.
- `Node` contains itself, which schematic and the hoister reject for class types. So `children`/`variants`/`ancestors`
  are typed `iterable` (the hoister keeps the hand-written schema), their schema is `NodeList::schema()`, an array of
  `$ref` to the `Node` component, and `NodeList` yields its nodes serialized already: the serializer can't read an
  object under a `$ref`. `Node::schema()` must use that very instance (`Nullable::wrap(NodeList::schema())`): the
  serializer picks the `anyOf` branch by identity, and validating against a `$ref` throws. `AssetTagList` does the same.

## Listing (`listNodes`, `NodeQuery`)

- One entry point node, then one CR query: `filterByHierarchy[type]` `childOf` (`findChildNodes`) or `descendantOf`
  (`findDescendantNodes`) with `filterByHierarchy[nodeAggregateId]`, or `filterByReference[nodeAggregateId]`
  (`findBackReferences`, a node once per reference) with an optional `filterByReference[name]`. Without either, the
  descendants of the default site's site node.
- The two can't be combined (400): they're separate queries, combining them would mean filtering in PHP and break the
  paging. Everything else (`filterByNodeType` as `NodeTypeCriteria` string, `filterByProperty` passed as is to
  `PropertyValueCriteriaParser`, `search` as `SearchTerm::fulltext`, `sort`, offset, limit) goes into the query, and
  `meta.total` comes from the matching `count*` call.
- Syntax errors are 400s before the entry node is looked up (404), including an empty node type name in
  `filterByNodeType` (`!`, `Foo:Bar,!`). Unknown node types are a 400 as well, checked against the entry point's CR.

## Writing nodes

The writes are resource-oriented operations that send CR commands, not a generic command endpoint: the commands' JSON
is the CR's internal serialization, Neos' services (`WorkspaceService`, …) would be bypassed, and one operation for all
commands documents badly. Each kind of change is a sub-resource of its own (`/properties`, `/references`, `/tags`,
`/nodetype`), not one PATCH of the node sending several commands.

`Nodes.php` helpers: `send()` handles a command (or a closure of several) and maps `AccessDenied` to a 403 (workspace
roles and node privileges are up to the CR's auth provider, as for reads); `shinesThrough()` is the 409 for
`DimensionSpacePointIsNotYetOccupied`; `nodeType()` the 422 for a node type that's gone; `reread()`/`read()` read the
node again for the response. A command's origin is always the requested point, so a node that only shines through
there is a 409: the variant has to be created first (Neos UI creates it silently). Editors may write to live:
`Neos.Neos:Editor` inherits `Neos.Neos:LivePublisher`. `{}` sends no command (the CR rejects one without changes).

### createNode

- Sends `CreateNodeAggregateWithNode`, never `CreateRootNodeAggregateWithNode`: root nodes (`Neos.Neos:Sites`) exist
  once per CR and come with the site setup (`POST /sites`).
- Without a sibling the node is the parent's last child (the projection appends; the command's doc comment saying
  "first child" is wrong). The CR doesn't check that the sibling is a child of the parent (the projection would take
  the sibling's parent for the position), so the endpoint does (422).
- A parent that only shines through in the point covers it, so the node is created there, as in Neos.
- `references` (shape as `updateNodeReferences`) are validated by `PropertyValues::referencesToWrite()` before the
  node is created, then sent as `SetNodeReferences` right after `CreateNodeAggregateWithNode`, whose own references
  the CR doesn't validate (targets, constraints). If they fail it's a 422 and the node stays without them.
- Neos UI's node creation handlers (`nodeCreationHandlers`) are `@internal` and don't run; project handlers are
  skipped. `promotedElements` isn't needed (the client sends properties). The `uriPathSegment` one is reproduced:
  `UriPathSegments::appliesTo(NodeType)` (a document with a `uriPathSegment` property) is the one rule for
  `createNode` and `generateNodeUriPathSegment`. A document without one gets its title transliterated in the point's
  language, else (no title, or one that transliterates to nothing) `<node type suffix>-NNN`, as Neos UI does. Not
  unique among siblings, Neos' isn't either. Neos' "sync" button (`NodeUriPathSegmentGenerator`) takes the language of
  the origin instead of the requested point; the API takes the requested one.
- Response: `NodeCreated::node()`, `Location: nodes/{id}?workspaceName=…&dimensionSpacePoint=…`.

### updateNodeProperties, updateNodeReferences

- The body is flat, the properties or references by name as `Node` has them. `RequestBody\NodePropertiesUpdate` /
  `NodeReferencesUpdate` only validate it as a map (neos/jsonschema's `additionalProperties` is a bool, it can't
  describe the reference lists), the operation decodes the body itself.
- The node is looked up as for `getNode` because `PropertyValues` needs its node type. `PropertyCannotBeSet` is still
  mapped (422) in case the CR's type check and `PropertyValues` disagree.
- References: a name's list replaces all of its references in order, `[]` removes them, names left out stay (that's
  `SetNodeReferences`). The CR checks the targets: `ReferenceCannotBeSet` (constraints `nodeTypes`, `maxItems`,
  property types), `NodeAggregateCurrentlyDoesNotExist`, `NodeAggregateDoesCurrentlyNotCoverDimensionSpacePoint`,
  `NodeAggregateIsRoot` are 422s. It doesn't check that the account may read the targets; Neos UI doesn't either.

### Property conversion (`PropertyValues`)

The CR's own converters are `@internal` (so is Neos UI's `NodePropertyConversionService`), so `PropertyValues`
converts by the type the node type declares: `string`, `integer`, `float`, `boolean`, `array` as JSON values; dates as
RFC 3339 strings with or without milliseconds (impossible dates like `2026-02-31` are a 422, PHP would roll them over
with only a warning); assets (`AssetInterface`, `ImageInterface` — which isn't one —, and `array<…>` of them) as
`{id}` (`url` ignored), looked up and checked against the type. Other types (value objects, enums, URIs) are a 422 for
now, as are undeclared and `_`-prefixed properties. Reference properties convert by the types the reference declares
(`references.<name>.properties.<property>.type`). It throws `InvalidPropertyValue` with the message for the caller.

### deleteNode, restoreNode

- `deleteNode` soft removes like Neos UI's `Remove` change: `TagSubtree` with `NeosSubtreeTag::removed()` and
  `STRATEGY_ALL_SPECIALIZATIONS` in the requested point (`en_US` takes `en_UK` with it, not the other way round), also
  for a shine-through node. Deliberately never `RemoveNodeAggregate`: Neos only sends it from the
  `SoftRemovalGarbageCollector` (in live, after publish/discard, once no workspace conflicts and
  `Neos.Neos.softRemoval.garbageCollectionGracePeriod` is over), when pruning a site and in node migrations. Hard
  removals in a workspace lose the hierarchy (pending changes, publishing a document) and cause rebase conflicts.
- Neos' command hook rejects tethered and root nodes (`NodeAggregateIsTethered`, `NodeAggregateIsRoot`, 422s).
  Deleting a variant never brings back the fallback's content: the aggregate no longer covers those points. There is
  therefore no `DELETE …/variants/{point}`.
- `restoreNode` undoes it as Neos' trash does (`RestoreController` in Neos.Workspace.Ui, `@internal` like its
  `TrashItemFinder`): removed nodes are in no subgraph, so the workspace comes from `WorkspaceResolver` and the
  aggregate from the content graph (`ReadableWorkspace::mayReadNodeAggregate()`, else 404). `NodeRestoration` sends
  `UntagSubtree(removed)` with `STRATEGY_ALL_VARIANTS` in a point it is removed in
  (`getCoveredDimensionsTaggedBy(removed, withoutInherited: true)`), then the same for each ancestor removed itself.
  `AccessDenied` is a 403 directly (there is no node for `send()`). The trash listing is
  `listWorkspaceChanges?filterByChangeType=deleted` (Workspaces.md).

### tagNode, untagNode

- `PUT`/`DELETE …/tags/{tag}`, no body, `TagSubtree`/`UntagSubtree` with `STRATEGY_ALL_SPECIALIZATIONS`, like Neos
  UI's `_hidden`. Scope `nodes.update`: hiding is an edit in Neos.
- `PUT` is idempotent: `SubtreeIsAlreadyTagged` is a 200 with the node. `DELETE` of a tag the node doesn't have itself
  (also an inherited one) is `SubtreeIsNotTagged`, a 404.
- Any tag (`Schema\SubtreeTag`, the CR's `[a-z0-9_.-]{1,36}`) but `removed`, a 422 both ways and checked before the
  lookup: removing is `deleteNode`, removed nodes aren't found (`restoreNode`). Tethered + `disabled` is Neos'
  `NodeAggregateIsTethered`, a 422. No tag allowlist for now, though node privileges match tags: the auth provider
  only checks that the account may edit the node.

### changeNodeType

`ChangeNodeAggregateType` changes the aggregate in all points; `dimensionSpacePoint` only says where the node is
read. The conflict strategy is Neos UI's `MarkWithTag(removed)`: child nodes the new type doesn't allow (and obsolete
tethered ones) are soft removed like `deleteNode`, so `restoreNode` and the pending changes work for them, never
`STRATEGY_DELETE`. Unknown type is checked first (422), the same type sends no command. `NodeAggregateIsUntethered`
(a child named like a tethered child of the new type) is a 409.

### moveNode (`NodeMoveCommand`)

POST as an action: a node's position is no representation to GET and PUT. Neither parent nor sibling is a 400, checked
first. As Neos UI's `MoveInto`/`MoveBefore`, the parent is only sent if it changes, and the other neighbour is sent as
preceding sibling (the child before the sibling, or the parent's last child), which the CR uses where the sibling
isn't. The strategy is the node type's `options.moveNodeStrategy` (Neos.Neos.Ui configures `gatherAll` for documents,
`scatter` for content), `gatherAll` without one as the safe choice, an invalid one a 500. `scatter` moves exactly the
requested point, not even the ones falling back to it. The CR checks siblings here (unlike for creation);
`NodeAggregateIsDescendant`'s message names the parent twice.

### createNodeVariant (`NodeVariation`)

- The query's `workspaceName`/`dimensionSpacePoint` identify the source (its origin is copied, so a node shining
  through in `en_UK` is copied from `en_US`); the body's `dimensionSpacePoint` is the target. That one is no query
  parameter, so invalid or unknown is a 422 naming the body field, not `SubgraphResolver`'s 400. The target subgraph is
  read with the source node's visibility.
- As Neos does: a tethered node is varied with its nearest non-tethered ancestor (Neos UI's `Property` change), the
  ancestors not in the target point yet are varied first, top-down (`NodesController::adoptNodeAndParents()`, the
  translation of a document), and `copyContent` varies the regular non-document nodes below it
  (`createNodeVariantsForChildNodes()`), skipping ones in the target already. Each variant is a command of its own,
  nothing is rolled back.
- A variant in the target point already is a 409, checked first. Response: `NodeCreated::variant()`,
  `Location: ../{id}?…` relative to `…/nodes/{id}/variants`. Scope `nodes.create`.
