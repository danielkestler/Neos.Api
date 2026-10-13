# Neos.Api

An HTTP API for Neos 9, defined in PHP with [neos/openapi](https://github.com/neos/openapi) and secured with OAuth 2 by
Neos.OAuth.

## Usage

Every operation is below `/api`. The OpenAPI document at `/api/openapi.json` describes each operation, its parameters,
responses and scopes. Neos.Api.Docs serves it as Swagger UI on `/api/docs`.

Clients send an access token from Neos.OAuth as `Authorization: Bearer <token>`. Each scope (`nodes.read`,
`workspaces.publish`, …) is also a privilege in `Configuration/Policy.yaml`: a token works only if it was granted the
scope *and* its account has the privilege. Neos' node, workspace and media privileges apply on top.

Errors are [RFC 9457](https://www.rfc-editor.org/rfc/rfc9457) problem documents. Lists come as `{data, meta, links}`;
`include`, `sort` and paging work as in [JSON:API](https://jsonapi.org).

## Views

A view is a Fusion prototype inheriting from `Neos.Api:View` that renders JSON for a node. Only views listed in the
`Neos.Api.views` setting can be requested:

```yaml
Neos:
  Api:
    views:
      myView:
        prototype: "Vendor.Site:MyView"
        description: "What it returns"
```

## Tests

Tests that need a content repository run in `FLOW_CONTEXT=Testing/Ddev` and are skipped otherwise:

```sh
ddev exec 'FLOW_CONTEXT=Testing/Ddev bin/phpunit -c Build/BuildEssentials/PhpUnit/FunctionalTests.xml DistributionPackages/Neos.Api/Tests/Functional'
```

## License

MIT, see [LICENSE](LICENSE).
