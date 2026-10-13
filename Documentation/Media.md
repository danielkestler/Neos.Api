# Media

The media library: three endpoints, `Assets` (`/assets…`), `AssetTags` (`/assettags…`), `AssetCollections`
(`/assetcollections…`). Ported from Medienreaktor.NeosApi's `/media/…` paths, deliberately flat like every other
resource, each a regular endpoint folder whose scope prefix is the lowercased tag (`assettags.read`).

## Common

- All scopes are granted to `Neos.Neos:AbstractEditor`, as Neos grants managing the media library
  (`Neos.Media.Browser:ManageAssets`, `:ManageTags`, `:ManageAssetCollections`). Which assets, tags and collections the
  account sees is up to Neos' media privileges (Doctrine entity privileges, applied to the bearer's account).
- Models are `Model\…` (`use Neos\Media\Domain\Model;`), IDs Flow's persistence identifiers.
- Dependencies are one-way: Assets → AssetTags/AssetCollections. `AssetTags\Domain\AssetTagResolver` and
  `AssetCollections\Domain\AssetCollectionResolver` are the one way to look them up, with the error of where the ID
  came from: `resolve()` the path's 404, `resolveReferences()` the body's 422 for the first unknown one,
  `resolveFilter()` a query parameter's 400. They use `Infrastructure/Media/AssetTagFinder` and
  `AssetCollectionFinder` (null if unknown). `AssetTagReference(List)` is in `AssetTags\Schema`.
- Error order: the path's 404 first, then 400s (malformed fields), then 422s (unknown references, rules).
- Tag and collection membership are idempotent `PUT`/`DELETE` sub-resources without a body (`…/tags/{assetTagId}`,
  `…/collections/{assetCollectionId}`): `PUT` of a member changes nothing, `DELETE` of a non-member is a 404, as for
  node tags.
- `assetCount` (tags and collections) comes from one grouped DQL query (`Infrastructure/Media/AssetCounter`), not
  `TagRepository`'s native SQL, so the media privileges apply to the assets counted; image variants aren't counted,
  as in Neos' lists.

## AssetTags

- A tree: `AssetTag` contains its children through `AssetTagList`, as `Node` through `NodeList` (written-out schema,
  `$ref` items, pre-serialized). `AssetTagTree` builds it from all visible tags at once, no query per tag. The
  listing's data are the root tags, `meta.total` counts all.
- `PATCH` `parentId: null` moves to the root: `JsonBody::has($request, 'parentId')` tells it from a field left out.
  Only the `parent` column changes, the tag is never removed from the old parent's `children`: they have
  `orphanRemoval`, removing it would delete it. Moving into itself or a descendant is a 422.
- `DELETE` removes the tag with its descendants, as Neos does, deepest first, each detached from its assets first
  (`orphanRemoval` removes the tags, but not from the assets).

## AssetCollections

Flat, `{id, title, assetCount, tags}` (`tags` read-only). `DELETE` keeps the assets and unsets the collection of sites
that use it (the site's reference would keep it from being deleted), as the media browser does. Tags and collections
both have a `GET` of one item (Medienreaktor's API had none), so the `Location` of the 201 points somewhere.

## Assets

- The asset source is always in the path, as the CR is below `/cr/{id}`: `GET /assets` lists the sources,
  `/assets/{assetSourceId}` a source's assets (list and upload; no separate GET of a source),
  `/assets/{assetSourceId}/{assetId}` an asset.
- Every source is read the same way, through its asset proxy repository: `Schema\Asset::from(AssetProxyInterface)`,
  one shape for neos and remote sources (title, caption, copyright notice, tags and collections only for neos assets,
  `iptc` for remote ones).
- `Infrastructure/Media/AssetSourceFinder` finds sources and assets (null if unknown) and resets the source's
  repository filters every time: a source keeps one stateful repository, and the neos one swaps the repository it
  reads from by type, so after an image filter `getAssetProxy()` wouldn't find documents (`orderBy()` even sets the
  default orderings of the singleton `AssetRepository`). `AssetSourceFinder::NEOS` / `isNeos()` is the one rule for
  "may change and take uploads". `neosProxy()` wraps a neos asset without querying again.
- `Assets\Domain\AssetResolver` turns the path into the source or asset, or the 404; `resolveAssetToChange()` gives a
  422 for other sources (import first).
- `listAssets` (`AssetQuery`): `filterByAssetType`, `filterByCollection`, and one of `search`, `filterByTag`,
  `filterByUntagged` (the repositories have one finder each; several are a 400). A filter or sort the source doesn't
  support is a 400. Neos' list leaves out image variants; `listAssetVariants` lists an image's (unpaged, oldest first,
  `[]` for non-images, variants and remote assets).
- `PATCH` `tagIds` replaces tags one by one (`removeTag`/`addTag`), never `setTags()`: Doctrine would delete the join
  rows of tags the account can't see. `collectionIds` go through `setAssetCollections()`, the collections own the
  relation, the invisible ones stay.
- Titles: `Schema\AssetTitle` at most 255 characters (400); IPTC titles are cut to 255 on import.
- `DELETE` is a 409 while the asset is in use, checked once by the endpoint: `remove()` checks the usages, but not of
  variants, which nodes use as well.
- `listAssetUsages` reads `AssetService::getUsageReferences()`, each node in its workspace and point with the
  account's visibility, as `Nodes\Schema\Node` with its closest document; unreadable ones are only counted
  (`meta.inaccessible`). It returns node content, so it needs `assets.read` and `nodes.read` (one security entry, all
  of them required).
- `importAsset` is an action on the remote asset (`POST …/{assetId}/import`; `POST /assets/import` would clash with an
  upload to a source named `import`): `AssetSourceService::importAsset()`. A remote source that fails to deliver
  (`ResourceException`, connection, image file errors; Neos logs the details) is a 502 (`Response\AssetImportFailed`),
  an `AssetSourceServiceException` a 422.
- `createAssetVariant` creates an `ImageVariant` with a `CropRectangle`, only from images (for a variant use its
  `originalAssetId`), inside the image. An existing variant with the same crop (and nothing else) is returned, a 200.
- Creates return `Response\AssetCreated` (always a 201, `uploaded()`, `imported()`, `variant()` with a `Location`
  relative to each operation's URI); the 200 cases return a plain `Asset`.

## Uploads

- `POST /assets/{assetSourceId}` (`createAsset`, fields `file`, `title`, `collectionIds[]`, `tagIds[]`) and
  `PUT /assets/{assetSourceId}/{assetId}/file` (`replaceAssetFile`, fields `file`, `keepOriginalFilename`,
  `generateRedirects`) are multipart/form-data, read with `Infrastructure/Http/MultipartForm`.
  `Assets\Domain\AssetUpload` is the upload form, an upload is mapped to its model class by Neos'
  `AssetModelMappingStrategyInterface`.
- PHP only parses POST bodies itself. `MultipartBodyMiddleware` ("Neos.Api:multipartBody", before Flow's
  `parseBody`, which would read `php://input` as a query string) parses other methods with `request_parse_body()`:
  PHP's limits (`post_max_size`, `upload_max_filesize`, `max_file_uploads`), files in temporary files. Not in the CLI:
  the functional tests' requests have no `php://input`, so `MultipartForm` parses the body in memory when the
  `MultipartBodyMiddleware::PARSED` attribute is missing.
- A file already in neos (same SHA1) isn't imported again, as the media browser keeps one asset per resource: the
  response is that asset unchanged, a 200 (the fields aren't applied, that's `updateAsset`, and the upload only needs
  `assets.create`).
- A file named as an image that isn't readable as one is a 422 for upload and replace, and the imported file is
  deleted again. Replacing (`AssetService::replaceAssetResource()`) only takes a file of the same kind for images,
  audio and video (Flow's media type from the filename: a PDF in place of an image would break every rendering);
  variants have no file of their own (422).
- Not done (proposals): a configurable upload media type allowlist or size limit.

## Tests

Changing or deleting an asset makes Neos look up its usages and flush their content caches, which needs the content
repository: the media tests extend `MediaTestCase` (a `ContentRepositoryTestCase`, `Testing/Ddev`). Remote sources:
`Tests/Functional/Fixtures/FakeAssetSource` (assets `photo`, `long-title`, `broken`), registered with
`MediaTestCase::addFakeAssetSource()`. Multipart PUT: `MediaTestCase::sendMultipart()`.
