# Neos.Api

An HTTP API for Neos 9: content repositories, nodes, node types, workspaces, sites, users, the media library, data
sources and Fusion-rendered views. The API is defined in PHP with [neos/openapi](https://github.com/bwaidelich/neos-openapi)
and its Flow adapter, which also generates the OpenAPI document. It is secured with OAuth 2 by Neos.OAuth.

## Requirements

- PHP 8.4, Neos and Flow 9.2
- Neos.OAuth (the authorization server and the bearer token provider)
- MariaDB or MySQL (the content repository)
- neos/openapi with deepObject query parameters, from the branch `feature/deep-object-query-parameters` of
  [danielkestler/neos-openapi](https://github.com/danielkestler/neos-openapi), until
  [bwaidelich/neos-openapi#9](https://github.com/bwaidelich/neos-openapi/pull/9) is merged
- Optional: Neos.Api.Docs, the Swagger UI on `/api/docs`

## Usage

Every operation is below `/api`. The OpenAPI document is `/api/openapi.json`: it describes each operation, its
parameters, responses and the scopes it needs.

A client gets an access token from Neos.OAuth and sends it as `Authorization: Bearer <token>`. Each operation needs
one or more scopes (`nodes.read`, `workspaces.publish`, …). A scope is also a privilege of the same name in
`Configuration/Policy.yaml`. A token works only if it was granted the scope *and* its account has the privilege. So the
account's roles decide what is possible, and the scopes narrow that down for one client. Neos' own rules apply on top:
node privileges, workspace roles, media privileges.

Errors are [RFC 9457](https://www.rfc-editor.org/rfc/rfc9457) problem documents (`application/problem+json`). Lists
come as `{data, meta, links}`. `include`, `sort` and paging work as in [JSON:API](https://jsonapi.org).

## Endpoints

| Area | Paths | Documentation |
|---|---|---|
| Nodes | `/cr/{contentRepositoryId}/nodes…` | [Nodes](Documentation/Nodes.md) |
| Node types | `/cr/{contentRepositoryId}/nodetypes…` | [NodeTypes](Documentation/NodeTypes.md) |
| Workspaces | `/cr/{contentRepositoryId}/workspaces…`: roles, publishing, changes, events | [Workspaces](Documentation/Workspaces.md) |
| Sites, content repositories | `/sites…`, `/cr…` | [Sites](Documentation/Sites.md) |
| Users, the own account, options | `/users…`, `/me…`, `/options…` | [Users](Documentation/Users.md) |
| Media | `/assets…`, `/assettags…`, `/assetcollections…` | [Media](Documentation/Media.md) |
| Views, data sources | `/views…`, `/datasources…` | [Views](Documentation/Views.md) |

The documentation explains why the endpoints behave as they do: the Neos internals behind them, and how content
repository errors map to status codes. What each operation does is in its description in the OpenAPI document.

## Views

A view is a Fusion prototype that inherits from `Neos.Api:View` and renders JSON for a node, with the Fusion of the
node's site. Only the views listed in the `Neos.Api.views` setting can be requested:

```yaml
Neos:
  Api:
    views:
      myView:
        prototype: "Vendor.Site:MyView"
        description: "What it returns"
```

The package ships `documentNodesTree`, `contentNodesTree`, `document` and `fusionPathHtml`. See
[Views](Documentation/Views.md).

## Tests

Functional tests are in `Tests/Functional`. The tests that need a content repository run with MariaDB in
`FLOW_CONTEXT=Testing/Ddev` and are skipped in the plain Testing context (SQLite):

```sh
ddev exec 'FLOW_CONTEXT=Testing/Ddev bin/phpunit -c Build/BuildEssentials/PhpUnit/FunctionalTests.xml DistributionPackages/Neos.Api/Tests/Functional/Endpoint/NodesTest.php'
```

## License

MIT, see [LICENSE](LICENSE).
