# Sites, domains, content repositories

## Sites (`/sites`)

- `sites.read` is granted to `Neos.Neos:AbstractEditor` (the entry point to the nodes), `sites.create`/`update`/
  `delete` to `Neos.Neos:Administrator` only.
- A site is identified by its node name (`/sites/{siteNodeName}`). `Site.nodeAggregateId` is the site node, the entry
  point for Nodes; `defaultDimensionSpacePoint` the point the frontend renders the home page in.
- `siteNode()` reads the site node in live with the account's visibility. It is null (not an error) when the account
  may not read it or the CR can't be built or isn't set up, including Neos' workspace metadata table missing (its
  migrations didn't run, `RuntimeException` 1727782164): sites and domains are managed without a CR, e.g. before
  `./flow cr:setup`.
- `createSite` goes through `SiteService::createSite()`. The node type is checked up front (a non-abstract
  `Neos.Neos:Site`, else 422): the service adds the site before it checks the node type. A node name derived from the
  name is transliterated as `SiteService` does and cut to 250 characters (`SiteNodeName::MAX_LENGTH`): transliterating
  can make it longer (北京 is `bei-jing`). A taken node name is a 409, an unknown package a 422.
- `deleteSite` is `SiteService::pruneSite()`: the site node in all workspaces goes with it, unpublished changes
  included.

## Domains (`/sites/{siteNodeName}/domains/{domainId}`)

- Domains are resources like any other: `POST …/domains` is a 201 `DomainCreated` with `Location: domains/{id}`
  (relative to `…/sites/{siteNodeName}/domains`), `GET`/`PATCH …/domains/{id}` return the `Domain`, `DELETE` is a 204.
  The site's `domains` stay a plain array inside `Site`. All writes need `sites.update`.
- A domain of another site is a 404 (`findDomain()`); a host name is unique among all domains (409), compared
  exactly, not with `findOneByHost()`, which matches subdomains as well.
- On create the domain is also added to the site's `domains` collection: the inverse side, which
  `Site::getPrimaryDomain()` falls back to.
- `PATCH`: `scheme` or `port` `null` removes it (`JsonBody::has()`), the domain then works with the request's.
  Deactivating the primary domain unsets it, so the site falls back to its first active domain; `updateSite` only takes
  an active domain as `primaryDomainId` (422 otherwise). Deleting the primary domain unsets it as well.
- `Domain.url` and `Site.primaryDomain` are `[scheme://]hostname[:port]` (the domain's string form).

### Upstream: `X|null` documents no 204

`deleteDomain` and `deleteSite` return `NotFound|null`. neos/openapi reflects a return type `X|null` as a nullable
named type, and `ApiCompiler::returnBranches` then documents no 204 for it; at runtime the operation still answers
204. Union types with more members (`NotFound|Forbidden|null`) are documented correctly. To be reported upstream;
don't work around it by changing return types.

## Content repositories (`/cr`)

- `GET /cr` and `GET /cr/{contentRepositoryId}` (`contentrepositories.read`, granted to `Neos.Neos:Editor`): the CRs of
  the `Neos.ContentRepositoryRegistry` settings with their content dimensions (labels translated). `/cr` is short
  because everything content lives below it.
- A CR that can't be built (`InvalidConfigurationException`, logged) is left out of the list and a 404 in `get`, so a
  broken configuration doesn't break the listing.
- `Infrastructure/ContentRepository/ContentRepositoryFinder::find()` is how other endpoints get a CR: null for an
  unknown one (the registry would throw), the endpoint answers `NotFound::contentRepository($id)`.
