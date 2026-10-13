# Views and data sources

## Views (`/views`)

- `GET /views/{viewName}` renders a Fusion prototype inheriting from `Neos.Api:View`
  (`Resources/Private/Fusion/`, auto-included in every site) for a node, with the Fusion of the node's site. Only the
  views in the `Neos.Api.views` settings are exposed (name → `prototype`, `description`, optional `renderErrors`; `~`
  removes one). The prototype encodes its data to JSON itself, the endpoint decodes it and returns `RenderedView`
  (free-form, `ViewData` only describes it).
- The node is given by query parameters (`contentRepositoryId`, `nodeAggregateId`, `workspaceName`,
  `dimensionSpacePoint`), all optional, resolved by `Nodes\Domain\SubgraphResolver` (same errors as Nodes): neos/openapi
  has no optional path parameters. Without `nodeAggregateId` it's the default site's site node, as for the frontend's
  home page.
- `renderingMode` (default `frontend`, else a key of `Neos.Neos.userInterface.editPreviewModes`) becomes the Fusion
  global. Other modes need `Neos.Neos:Backend.GeneralAccess`, as in Neos: the argument-dependent rule `security`
  can't express, checked with `AccountPrivileges::isGranted()` and answered with `Forbidden`. `frontend` in live
  leaves disabled nodes out as the frontend does; anything else renders with the account's visibility, as Neos'
  preview does.
- `arguments[name]=value` (`Shared\Parameter\Arguments`, deepObject, shared with DataSources) reach the view as the
  context variable `arguments`. A view throws `Infrastructure\Fusion\InvalidViewArgument` for one it can't use, which
  is a 400 (Fusion wraps nested exceptions, `InvalidViewArgument` unwraps them).
- Fusion errors are thrown (500), never rendered into the output, unless the view sets `renderErrors: true`: then Neos'
  handling applies, e.g. a content element shows its error in its place (`fusionPathHtml` does, as the backend).

### Rendering (`Infrastructure/Fusion`)

- `ViewRenderer` renders the prototype on the path `neosApiView<Prototype.Name>` (no root path needed) through
  `ViewFusionView`, a subclass of Neos' `FusionView`, so views get the frontend's context (`node`, `documentNode`,
  `site`), globals (`request`, `renderingMode`) and locale. The node decides CR, workspace and point for the whole
  rendering: every node Fusion finds from it is in the same subgraph.
- The view's JSON is decoded with `JsonValues::decodeObject()`: arrays, but empty objects stay `stdClass`, so they
  stay `{}`. schematic's serializer empties a non-empty `stdClass` (it keeps only declared properties), an empty one
  it keeps. `RenderedView` takes `array|\stdClass`; an empty data structure is returned as `{}`. `JsonValues` is used
  by `ViewRenderer`, `DataSourceResult` and `Workspaces\Schema\Event` as well.

### The four shipped views

- `documentNodesTree` (the document's child documents) and `contentNodesTree` (the node's child content and
  collections): one level per call, for trees of an editing UI, built from `Neos.Api:NodeItem` /
  `Neos.Api:DocumentNodeItem`. They are also the examples for writing views.
- `document`: the whole document at once, `Neos.Api:ContentNode` nests all content below it, with `properties` and
  `references` as `Node` has them (`Neos.Api.Node.properties()`, `references()`, the same `NodeSerializer`).
- `fusionPathHtml` (`renderErrors: true`): the HTML of a content element as the page renders it, at its Fusion path
  `arguments[fusionPath]` (its `data-__neos-fusion-path` in `renderingMode=inPlace`), e.g. to replace it in a preview
  after a change. `Neos.Api:FusionPath` (`FusionPathImplementation`) only renders in a rendering mode other than
  frontend (so only with backend access), only paths below `root` or the mode's `fusionRenderingPath`, without empty
  or `__meta` segments, else a 400: not any path of the site's Fusion. A path ending in the element a
  `Neos.Neos:ContentCase` chose is rendered from the case, so a change that makes it choose another one shows (as
  Neos UI's `RenderedNodeDomAddress::getFusionPathForContentRendering()`).

### Eel: `Neos.Api.Node` (`Infrastructure/Eel/NodeHelper`)

What views need beyond `Neos.Node`: `childNodes()`/`hasChildNodes()` by node type filter in their order (FlowQuery's
`children()` with several filters returns them grouped), `properties()`, `references()`, `label()`, `isHidden()`,
`isHiddenByAncestor()`, `isRemoved()` (kept: `NodeItem.fusion` outputs it), `isShineThrough()`, `timestamps()`,
`nodeTypeIcon()`, `nodeTypeLabel()` (via `NodeTypeUi`, the same as the node types endpoint). Eel creates it with
`new`: injected properties, not readonly.

### Upstream: non-JSON bodies

neos/openapi always JSON-encodes response bodies, so HTML or RSS views need an upstream change first, proposed on the fork's branch `feature/non-json-response-bodies`.

## Data sources (`/datasources`)

- `GET /datasources` lists the implementations of Neos' `DataSourceInterface`
  (`DataSourceController::getDataSources()`, as Neos' own `/neos/service/data-source`), `GET /datasources/{id}` calls
  one and returns `{data: …}` (`DataSourceResult`, free-form, `DataSourceData` only describes it).
- Not below `/cr`: data sources belong to no CR, only the node they're called for does. The node is optional, given
  as for Views (`SubgraphResolver`); without `nodeAggregateId`, `contentRepositoryId`/`workspaceName`/
  `dimensionSpacePoint` are a 400. Arguments are `Shared\Parameter\Arguments` (what the Neos UI sends from
  `editorOptions.dataSourceAdditionalData`).
- A data source that throws is a 500 (`DataSourceFailed`) with its message in the log only: data sources are other
  packages' code, their messages may tell about internals. Data sources get no `ControllerContext` (neos/openapi has
  none), ones relying on `AbstractDataSource::$controllerContext` fail.
- Scope `datasources.read`, granted to `Neos.Neos:AbstractEditor` as Neos grants `Neos.Neos:Backend.DataSource`.

## Tests

`ViewsTest`, `DataSourcesTest` (data sources in `Tests/Functional/Fixtures/`, the Testing context registers functional
test classes), and `Tests/Functional/Infrastructure/Fusion/FusionPathTest` (a Fusion runtime from
`RuntimeFactory::createFromSourceCode()`, no CR).
