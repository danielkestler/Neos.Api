# Neos.Api

The HTTP API for Neos 9 and the Content Repository, defined in PHP with [neos/openapi](https://github.com/neos/openapi) and secured with OAuth 2.

## Usage

Every operation is below `/api`. The OpenAPI document at `/api/openapi.json` describes each operation, its parameters,
responses and scopes. The optional Neos.Api.Docs package serves it as Swagger UI on `/api/docs`.

Clients send an access token as `Authorization: Bearer <token>`. Each scope (`nodes.read`,
`workspaces.publish`, …) is also a privilege in `Configuration/Policy.yaml`: a token works only if it was granted the
scope _and_ its account has the privilege. Neos' node, workspace and media privileges apply on top.

Errors are [RFC 9457](https://www.rfc-editor.org/rfc/rfc9457) problem documents. Lists come as `{data, meta, links}`;
`include`, `sort` and paging work as in [JSON:API](https://jsonapi.org).

## License

MIT, see [LICENSE](LICENSE).
