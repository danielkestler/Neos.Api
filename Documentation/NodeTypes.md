# Node types

`Endpoint/NodeTypes`: `GET /cr/{contentRepositoryId}/nodetypes` and `…/nodetypes/{nodeTypeName}`, scope
`nodetypes.read` (granted to `Neos.Neos:Editor`).

## Why nested under the content repository

Each content repository builds its own `NodeTypeManager` (its `nodeTypeManager.factoryObjectName`), so a node type
name is only unique within one. The content dimensions instead stay embedded in `ContentRepository` (Sites.md): small,
and every client needs them.

## Shape

- Always: `name`, translated `label` (the name if it has none), `icon`, `isAbstract`, `isFinal`, declared
  `superTypes`, `ui.group` as `group`, `ui.position` as `position` (an integer or a string, so the schema is written
  out). The list includes abstract node types, sorted by name.
- Only if included (`IncludePaths`, list) or always (get): `properties` (name, type, label; `_`-prefixed internal ones
  like `_hidden` left out), `references` (name, label, maxItems), `configuration` (the whole merged configuration),
  `allowedChildNodeTypes`, `tetheredChildNodes` (name, node type, and the node types allowed below it). What's not
  included is `null`, so the list stays cheap: `configuration` is only computed when included.
- Which node types a node may get as children follows from the model alone: a regular node's from its node type's
  `allowedChildNodeTypes`, a tethered node's (a page's main collection) from its parent's node type's
  `tetheredChildNodes` entry by name. Candidates are the node types that are neither abstract nor root,
  `NodeType::childNodeTypeCandidates()`, computed once per list, not per node type.
- `configuration` is a free-form map: an empty one is a `\stdClass` so it encodes as `{}` (the serializer can't tell an
  empty map from an empty list). String values under the keys `label`, `message` and `placeholder` are translated like
  every other label.
- `filterBySuperType` keeps the node type and the ones inheriting from it, directly or not; unknown is a 400.

## Icon and label (`Infrastructure/ContentRepository/NodeTypeUi`)

Shared with Fusion (`Neos.Api.Node.nodeTypeIcon()`, `nodeTypeLabel()`), so the node types endpoint and the views show
the same. Node types configure anything from bare legacy names (`file`) over `icon-file` to full classes
(`fas fa-file`). Neos' `IconNameMappingService` only maps `icon-` names, so bare ones get the prefix first; full
classes (with a space) are kept. `nodeTypeLabel()` is null for a node type that no longer exists.
