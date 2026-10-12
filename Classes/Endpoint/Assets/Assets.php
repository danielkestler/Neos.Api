<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\Assets;

use Doctrine\Common\Collections\ArrayCollection;
use Neos\Api\Endpoint\AssetCollections\Schema\AssetCollectionId;
use Neos\Api\Endpoint\Assets\RequestBody\AssetUpdate;
use Neos\Api\Endpoint\Assets\RequestBody\AssetVariantCreate;
use Neos\Api\Endpoint\Assets\Response\AssetCreated;
use Neos\Api\Endpoint\Assets\Schema\Asset;
use Neos\Api\Endpoint\Assets\Schema\AssetId;
use Neos\Api\Endpoint\Assets\Schema\AssetList;
use Neos\Api\Endpoint\Assets\Schema\AssetSource;
use Neos\Api\Endpoint\Assets\Schema\AssetSourceId;
use Neos\Api\Endpoint\Assets\Schema\AssetSourceList;
use Neos\Api\Endpoint\Assets\Schema\AssetSourceListing;
use Neos\Api\Endpoint\Assets\Schema\AssetType;
use Neos\Api\Endpoint\Assets\Schema\AssetUsage;
use Neos\Api\Endpoint\Assets\Schema\AssetUsageList;
use Neos\Api\Endpoint\Assets\Schema\AssetUsageListing;
use Neos\Api\Endpoint\Assets\Schema\AssetUsageListingMeta;
use Neos\Api\Endpoint\Assets\Schema\PaginatedAssetListing;
use Neos\Api\Endpoint\AssetTags\Schema\AssetTagId;
use Neos\Api\Endpoint\Nodes\Schema\Node;
use Neos\Api\Infrastructure\ContentRepository\ContentSubgraphs;
use Neos\Api\Infrastructure\ContentRepository\NodeSerializer;
use Neos\Api\Security\ApiAuthContextProvider;
use Neos\Api\Security\ApiScopes;
use Neos\Api\Shared\Parameter\Limit;
use Neos\Api\Shared\Parameter\Offset;
use Neos\Api\Shared\Parameter\Sort;
use Neos\Api\Shared\Response\BadRequest;
use Neos\Api\Shared\Response\Conflict;
use Neos\Api\Shared\Response\NotFound;
use Neos\Api\Shared\Response\UnprocessableContent;
use Neos\Api\Shared\Schema\ListingLinks;
use Neos\Api\Shared\Schema\ListingMeta;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindClosestNodeFilter;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Flow\ResourceManagement\PersistentResource;
use Neos\Flow\ResourceManagement\ResourceManager;
use Neos\Media\Domain\Model;
use Neos\Media\Domain\Model\AssetSource\AssetProxy\AssetProxyInterface;
use Neos\Media\Domain\Model\AssetSource\AssetProxy\HasRemoteOriginalInterface;
use Neos\Media\Domain\Model\AssetSource\SupportsCollectionsInterface;
use Neos\Media\Domain\Model\AssetSource\SupportsSortingInterface;
use Neos\Media\Domain\Model\AssetSource\SupportsTaggingInterface;
use Neos\Media\Domain\Repository\AssetCollectionRepository;
use Neos\Media\Domain\Repository\AssetRepository;
use Neos\Media\Domain\Repository\TagRepository;
use Neos\Media\Domain\Service\AssetService;
use Neos\Media\Domain\Service\AssetSourceService;
use Neos\Media\Domain\Strategy\AssetModelMappingStrategyInterface;
use Neos\Media\Exception\AssetServiceException;
use Neos\Media\Exception\AssetSourceServiceException;
use Neos\Neos\AssetUsage\Dto\AssetUsageReference;
use Neos\Neos\Domain\Service\NodeTypeNameFactory;
use Neos\OpenApi\Attributes\Operation;
use Neos\OpenApi\Attributes\Parameter;
use Neos\OpenApi\Attributes\RequestBody;
use Neos\Schematic\Exception\InstantiationFailed;
use Neos\Utility\MediaTypes;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * The assets of the asset sources: neos, the media library of Neos, and remote ones
 */
final readonly class Assets
{
    private const string NEOS = 'neos';

    /**
     * The sort fields of listAssets → the properties the asset sources sort by, as the Neos media browser does
     */
    private const array SORT_FIELDS = [
        'name' => 'resource.filename',
        'lastModified' => 'lastModified',
    ];

    public function __construct(
        private AssetSourceService $assetSourceService,
        private AssetResolver $assetResolver,
        private AssetService $assetService,
        private AssetRepository $assetRepository,
        private TagRepository $tagRepository,
        private AssetCollectionRepository $assetCollectionRepository,
        private ContentSubgraphs $contentSubgraphs,
        private NodeSerializer $nodeSerializer,
        private PersistenceManagerInterface $persistenceManager,
        private ResourceManager $resourceManager,
        private AssetModelMappingStrategyInterface $assetModelMappingStrategy,
    ) {
    }

    #[Operation(
        path: '/assets',
        method: 'GET',
        summary: 'List the asset sources',
        description: 'The configured asset sources, whose assets are below /assets/{assetSourceId}: neos, the media library of Neos, and remote ones like a DAM, with what they support, in their configured order.',
        operationId: 'listAssetSources',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::ASSETS_READ],
        ],
    )]
    public function listSources(): AssetSourceListing
    {
        return AssetSourceListing::of(new AssetSourceList(...array_map(
            AssetSource::from(...),
            array_values($this->assetSourceService->getAssetSources()),
        )));
    }

    #[Operation(
        path: '/assets/{assetSourceId}',
        method: 'GET',
        summary: 'List the assets of an asset source',
        description: 'A page of the assets of the asset source (see listAssetSources), an unknown one is a 404. filterByAssetType narrows down to a type, filterByAssetCollection to a collection. Then either search (in title, caption and filename), filterByTag or filterByUntagged: the asset sources can do only one of them at a time, several are a 400. The tag and collection filters need a source that supports them (see listAssetSources), unknown tags and collections are 400s. sort is by name or lastModified, -lastModified if not given, for a source that supports sorting; sort on any other is a 400. Image variants aren\'t listed, they belong to their image. offset and limit (25 by default, 100 at most) choose the page, meta.total and links tell about the others.',
        operationId: 'listAssets',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::ASSETS_READ],
        ],
    )]
    public function list(
        AssetSourceId $assetSourceId,
        ServerRequestInterface $request,
        #[Parameter(in: 'query', description: 'Only the assets of this type')]
        AssetType|null $filterByAssetType = null,
        #[Parameter(in: 'query', description: 'Only the assets in this collection')]
        AssetCollectionId|null $filterByAssetCollection = null,
        #[Parameter(in: 'query', description: 'Only the assets with this tag')]
        AssetTagId|null $filterByTag = null,
        #[Parameter(in: 'query', description: 'Only the assets without tags, if true')]
        bool $filterByUntagged = false,
        #[Parameter(in: 'query', description: 'Only the assets that match the term')]
        string|null $search = null,
        #[Parameter(in: 'query', description: 'The fields to sort by: name, lastModified, each ascending unless prefixed with -. -lastModified if omitted')]
        Sort|null $sort = null,
        #[Parameter(in: 'query', description: 'How many items to skip, 0 if omitted')]
        Offset|null $offset = null,
        #[Parameter(in: 'query', description: 'How many items at most, 25 if omitted, 100 at most')]
        Limit|null $limit = null,
    ): PaginatedAssetListing|BadRequest|NotFound {
        $offset ??= Offset::none();
        $limit ??= Limit::default();
        $search = $search !== null && trim($search) !== '' ? $search : null;
        if (count(array_filter([$search !== null, $filterByTag !== null, $filterByUntagged])) > 1) {
            return BadRequest::because('search, filterByTag and filterByUntagged can\'t be combined');
        }
        $orderings = [];
        foreach ($sort?->fields() ?? [] as ['field' => $field, 'descending' => $descending]) {
            if (!isset(self::SORT_FIELDS[$field])) {
                return BadRequest::because(sprintf('The assets can\'t be sorted by %s, only by %s', $field, implode(', ', array_keys(self::SORT_FIELDS))));
            }
            $orderings[self::SORT_FIELDS[$field]] = $descending ? SupportsSortingInterface::ORDER_DESCENDING : SupportsSortingInterface::ORDER_ASCENDING;
        }

        $assetSource = $this->assetResolver->findAssetSource($assetSourceId);
        if ($assetSource === null) {
            return AssetResolver::noAssetSource($assetSourceId);
        }
        $repository = $this->assetResolver->repository($assetSource);
        if (($filterByTag !== null || $filterByUntagged) && !$repository instanceof SupportsTaggingInterface) {
            return BadRequest::because(sprintf('The asset source %s doesn\'t support tags', $assetSourceId->value));
        }
        if ($filterByAssetCollection !== null && !$repository instanceof SupportsCollectionsInterface) {
            return BadRequest::because(sprintf('The asset source %s doesn\'t support collections', $assetSourceId->value));
        }
        if ($orderings !== [] && !$repository instanceof SupportsSortingInterface) {
            return BadRequest::because(sprintf('The asset source %s doesn\'t support sorting', $assetSourceId->value));
        }

        if ($filterByAssetType !== null) {
            $repository->filterByType($filterByAssetType->toAssetTypeFilter());
        }
        if ($filterByAssetCollection !== null) {
            $assetCollection = $this->assetCollectionRepository->findByIdentifier($filterByAssetCollection->value);
            if (!$assetCollection instanceof Model\AssetCollection) {
                return BadRequest::because(sprintf('There is no asset collection with the ID %s', $filterByAssetCollection->value));
            }
            /** @var SupportsCollectionsInterface $repository */
            $repository->filterByCollection($assetCollection);
        }
        if ($repository instanceof SupportsSortingInterface) {
            $repository->orderBy($orderings ?: [self::SORT_FIELDS['lastModified'] => SupportsSortingInterface::ORDER_DESCENDING]);
        }
        if ($filterByTag !== null) {
            $tag = $this->tagRepository->findByIdentifier($filterByTag->value);
            if (!$tag instanceof Model\Tag) {
                return BadRequest::because(sprintf('There is no asset tag with the ID %s', $filterByTag->value));
            }
            $result = $repository->findByTag($tag);
        } elseif ($filterByUntagged) {
            $result = $repository->findUntagged();
        } elseif ($search !== null) {
            $result = $repository->findBySearchTerm($search);
        } else {
            $result = $repository->findAll();
        }

        $total = $result->count();
        $query = $result->getQuery();
        $query->setOffset($offset->value);
        $query->setLimit($limit->value);
        return new PaginatedAssetListing(
            new AssetList(...array_map($this->asset(...), $query->execute()->toArray())),
            new ListingMeta($total),
            ListingLinks::for($request, $offset, $limit, $total),
        );
    }

    #[Operation(
        path: '/assets/{assetSourceId}/{assetId}',
        method: 'GET',
        summary: 'Get an asset',
        description: 'An asset of an asset source, image variants included.',
        operationId: 'getAsset',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::ASSETS_READ],
        ],
    )]
    public function get(AssetSourceId $assetSourceId, AssetId $assetId): Asset|NotFound
    {
        $proxy = $this->assetResolver->findAsset($assetSourceId, $assetId);
        return $proxy instanceof AssetProxyInterface ? $this->asset($proxy) : $proxy;
    }

    #[Operation(
        path: '/assets/{assetSourceId}/{assetId}/usage',
        method: 'GET',
        summary: 'List the usages of an asset',
        description: 'The nodes that use the asset (or a variant of it) in a property, in every workspace and dimension space point, each with its document. Only the ones the account may read are listed, meta.inaccessible counts the others: an asset is in use even if none are listed. A remote asset that isn\'t imported has none.',
        operationId: 'listAssetUsages',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::ASSETS_READ],
        ],
    )]
    public function listUsages(AssetSourceId $assetSourceId, AssetId $assetId): AssetUsageListing|NotFound
    {
        $proxy = $this->assetResolver->findAsset($assetSourceId, $assetId);
        if (!$proxy instanceof AssetProxyInterface) {
            return $proxy;
        }
        $localAssetId = $proxy->getLocalAssetIdentifier();
        $asset = $localAssetId !== null ? $this->assetRepository->findByIdentifier($localAssetId) : null;
        $usages = [];
        $inaccessible = 0;
        foreach ($asset instanceof Model\AssetInterface ? $this->assetService->getUsageReferences($asset) : [] as $reference) {
            $usage = $reference instanceof AssetUsageReference ? $this->usage($reference) : null;
            if ($usage === null) {
                $inaccessible++;
                continue;
            }
            $usages[] = $usage;
        }
        return new AssetUsageListing(new AssetUsageList(...$usages), new AssetUsageListingMeta(count($usages), $inaccessible));
    }

    #[Operation(
        path: '/assets/{assetSourceId}/{assetId}',
        method: 'PATCH',
        summary: 'Change an asset',
        description: 'Changes the metadata of an asset of neos, what is left out or null stays as it is. tagIds and assetCollectionIds replace all tags and collections of the asset ([] removes them), unknown ones are a 422. Assets of other sources are a 422: import them first.',
        operationId: 'updateAsset',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::ASSETS_UPDATE],
        ],
    )]
    public function update(
        AssetSourceId $assetSourceId,
        AssetId $assetId,
        #[RequestBody(description: 'The metadata to change')] AssetUpdate $patch,
    ): Asset|NotFound|UnprocessableContent {
        $asset = $this->assetResolver->findAssetToChange($assetSourceId, $assetId);
        if (!$asset instanceof Model\Asset) {
            return $asset;
        }
        $tags = [];
        foreach ($patch->tagIds ?? [] as $tagId) {
            $tag = $this->tagRepository->findByIdentifier($tagId->value);
            if (!$tag instanceof Model\Tag) {
                return UnprocessableContent::because(sprintf('There is no asset tag with the ID %s', $tagId->value));
            }
            $tags[] = $tag;
        }
        $assetCollections = [];
        foreach ($patch->assetCollectionIds ?? [] as $assetCollectionId) {
            $assetCollection = $this->assetCollectionRepository->findByIdentifier($assetCollectionId->value);
            if (!$assetCollection instanceof Model\AssetCollection) {
                return UnprocessableContent::because(sprintf('There is no asset collection with the ID %s', $assetCollectionId->value));
            }
            $assetCollections[] = $assetCollection;
        }
        $patch->applyTo($asset);
        if ($patch->tagIds !== null) {
            $asset->setTags(new ArrayCollection(self::distinct($tags)));
        }
        if ($patch->assetCollectionIds !== null) {
            // a new collection: setAssetCollections() compares it with the current one
            $asset->setAssetCollections(new ArrayCollection(self::distinct($assetCollections)));
        }
        return $this->changed($asset);
    }

    #[Operation(
        path: '/assets/{assetSourceId}/{assetId}',
        method: 'DELETE',
        summary: 'Delete an asset',
        description: 'Deletes an asset of neos (an image with its variants), as the Neos media browser does. An asset in use (see listAssetUsages, also in nodes the account can\'t read) is a 409, assets of other sources are a 422.',
        operationId: 'deleteAsset',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::ASSETS_DELETE],
        ],
    )]
    public function delete(AssetSourceId $assetSourceId, AssetId $assetId): NotFound|Conflict|UnprocessableContent|null
    {
        $asset = $this->assetResolver->findAssetToChange($assetSourceId, $assetId);
        if (!$asset instanceof Model\Asset) {
            return $asset;
        }
        // Neos doesn't check variants, but nodes use them as well
        if ($this->assetService->isInUse($asset)) {
            return Conflict::because('The asset is in use, see listAssetUsages');
        }
        try {
            $this->assetRepository->remove($asset);
        } catch (AssetServiceException $exception) {
            return Conflict::because($exception->getMessage());
        }
        $this->persistenceManager->persistAll();
        return null;
    }

    #[Operation(
        path: '/assets/{assetSourceId}/{assetId}/tags/{assetTagId}',
        method: 'PUT',
        summary: 'Tag an asset',
        description: 'Gives an asset of neos the tag, nothing happens if it has it already. An unknown tag is a 404, assets of other sources are a 422.',
        operationId: 'tagAsset',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::ASSETS_UPDATE],
        ],
    )]
    public function tag(AssetSourceId $assetSourceId, AssetId $assetId, AssetTagId $assetTagId): Asset|NotFound|UnprocessableContent
    {
        $asset = $this->assetResolver->findAssetToChange($assetSourceId, $assetId);
        if (!$asset instanceof Model\Asset) {
            return $asset;
        }
        $tag = $this->tagRepository->findByIdentifier($assetTagId->value);
        if (!$tag instanceof Model\Tag) {
            return NotFound::because(sprintf('There is no asset tag with the ID %s', $assetTagId->value));
        }
        return $asset->addTag($tag) ? $this->changed($asset) : $this->asset($this->assetResolver->neosProxy($asset));
    }

    #[Operation(
        path: '/assets/{assetSourceId}/{assetId}/tags/{assetTagId}',
        method: 'DELETE',
        summary: 'Untag an asset',
        description: 'Removes the tag from an asset of neos. A tag the asset doesn\'t have is a 404, assets of other sources are a 422.',
        operationId: 'untagAsset',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::ASSETS_UPDATE],
        ],
    )]
    public function untag(AssetSourceId $assetSourceId, AssetId $assetId, AssetTagId $assetTagId): Asset|NotFound|UnprocessableContent
    {
        $asset = $this->assetResolver->findAssetToChange($assetSourceId, $assetId);
        if (!$asset instanceof Model\Asset) {
            return $asset;
        }
        $tag = $this->tagRepository->findByIdentifier($assetTagId->value);
        if (!$tag instanceof Model\Tag || !$asset->removeTag($tag)) {
            return NotFound::because(sprintf('The asset has no tag with the ID %s', $assetTagId->value));
        }
        return $this->changed($asset);
    }

    #[Operation(
        path: '/assets/{assetSourceId}/{assetId}/collections/{assetCollectionId}',
        method: 'PUT',
        summary: 'Add an asset to a collection',
        description: 'Adds an asset of neos to the collection, nothing happens if it is in it already. An unknown collection is a 404, assets of other sources are a 422.',
        operationId: 'addAssetToCollection',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::ASSETS_UPDATE],
        ],
    )]
    public function addToCollection(AssetSourceId $assetSourceId, AssetId $assetId, AssetCollectionId $assetCollectionId): Asset|NotFound|UnprocessableContent
    {
        $asset = $this->assetResolver->findAssetToChange($assetSourceId, $assetId);
        if (!$asset instanceof Model\Asset) {
            return $asset;
        }
        $assetCollection = $this->assetCollectionRepository->findByIdentifier($assetCollectionId->value);
        if (!$assetCollection instanceof Model\AssetCollection) {
            return NotFound::because(sprintf('There is no asset collection with the ID %s', $assetCollectionId->value));
        }
        $assetCollections = $asset->getAssetCollections()->toArray();
        if (in_array($assetCollection, $assetCollections, true)) {
            return $this->asset($this->assetResolver->neosProxy($asset));
        }
        $asset->setAssetCollections(new ArrayCollection([...array_values($assetCollections), $assetCollection]));
        return $this->changed($asset);
    }

    #[Operation(
        path: '/assets/{assetSourceId}/{assetId}/collections/{assetCollectionId}',
        method: 'DELETE',
        summary: 'Remove an asset from a collection',
        description: 'Removes an asset of neos from the collection. A collection the asset isn\'t in is a 404, assets of other sources are a 422.',
        operationId: 'removeAssetFromCollection',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::ASSETS_UPDATE],
        ],
    )]
    public function removeFromCollection(AssetSourceId $assetSourceId, AssetId $assetId, AssetCollectionId $assetCollectionId): Asset|NotFound|UnprocessableContent
    {
        $asset = $this->assetResolver->findAssetToChange($assetSourceId, $assetId);
        if (!$asset instanceof Model\Asset) {
            return $asset;
        }
        $assetCollection = $this->assetCollectionRepository->findByIdentifier($assetCollectionId->value);
        $assetCollections = $asset->getAssetCollections()->toArray();
        if (!$assetCollection instanceof Model\AssetCollection || !in_array($assetCollection, $assetCollections, true)) {
            return NotFound::because(sprintf('The asset is in no asset collection with the ID %s', $assetCollectionId->value));
        }
        $asset->setAssetCollections(new ArrayCollection(array_values(array_filter($assetCollections, static fn (Model\AssetCollection $other) => $other !== $assetCollection))));
        return $this->changed($asset);
    }

    #[Operation(
        path: '/assets/{assetSourceId}',
        method: 'POST',
        summary: 'Upload an asset',
        description: 'Uploads a file into neos, the only asset source that takes uploads (others are a 422, an unknown one a 404), as an asset of the type its media type maps to (an image, document, video or audio), as the Neos media browser does. The body is multipart/form-data, not JSON (the document can\'t describe it): resource the file (required), title, assetCollectionId the collection to put it in, tagIds[] the tags to give it (repeated). A missing file or a malformed field is a 400, an unknown collection or tag a 422. A file that is in neos already (the same content) isn\'t added again: that asset gets the collection and tags and is the response, a 200 instead of the 201.',
        operationId: 'createAsset',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::ASSETS_CREATE],
        ],
    )]
    public function create(AssetSourceId $assetSourceId, ServerRequestInterface $request): AssetCreated|Asset|BadRequest|NotFound|UnprocessableContent
    {
        $assetSource = $this->assetResolver->findAssetSource($assetSourceId);
        if ($assetSource === null) {
            return AssetResolver::noAssetSource($assetSourceId);
        }
        if ($assetSource->getIdentifier() !== self::NEOS || $assetSource->isReadOnly()) {
            return UnprocessableContent::because(sprintf('The asset source %s takes no uploads, only neos does', $assetSourceId->value));
        }
        $form = MultipartForm::of($request);
        $file = $form->file('resource');
        if ($file === null) {
            return BadRequest::because($form->uploadError('resource'));
        }
        $tagIds = $form->strings('tagIds');
        $assetCollectionId = $form->string('assetCollectionId');
        if ($tagIds === null) {
            return BadRequest::because('tagIds must be repeated tagIds[] fields');
        }
        try {
            $tagIds = array_map(AssetTagId::fromString(...), $tagIds);
            $assetCollectionId = $assetCollectionId !== null ? AssetCollectionId::fromString($assetCollectionId) : null;
        } catch (InstantiationFailed) {
            return BadRequest::because('assetCollectionId must be the ID of an asset collection, tagIds[] IDs of asset tags');
        }
        $tags = [];
        foreach ($tagIds as $tagId) {
            $tag = $this->tagRepository->findByIdentifier($tagId->value);
            if (!$tag instanceof Model\Tag) {
                return UnprocessableContent::because(sprintf('There is no asset tag with the ID %s', $tagId->value));
            }
            $tags[] = $tag;
        }
        $assetCollection = $assetCollectionId !== null ? $this->assetCollectionRepository->findByIdentifier($assetCollectionId->value) : null;
        if ($assetCollectionId !== null && !$assetCollection instanceof Model\AssetCollection) {
            return UnprocessableContent::because(sprintf('There is no asset collection with the ID %s', $assetCollectionId->value));
        }

        // one asset per file, as the media browser does: checked before the file is imported
        $existing = $this->assetRepository->findOneByResourceSha1(self::sha1($file));
        $asset = $existing instanceof Model\Asset && !$existing instanceof Model\ImageVariant ? $existing : null;
        if ($asset === null) {
            $resource = $this->importResource($file);
            $className = $this->assetModelMappingStrategy->map($resource);
            $asset = new $className($resource);
            if (!$asset instanceof Model\Asset) {
                throw new \RuntimeException(sprintf('The asset model %s isn\'t an asset', $className), 1791800003);
            }
            $title = $form->string('title');
            if ($title !== null) {
                $asset->setTitle($title);
            }
            $this->assetRepository->add($asset);
        }
        foreach ($tags as $tag) {
            $asset->addTag($tag);
        }
        $assetCollections = $asset->getAssetCollections()->toArray();
        if ($assetCollection !== null && !in_array($assetCollection, $assetCollections, true)) {
            $asset->setAssetCollections(new ArrayCollection([...array_values($assetCollections), $assetCollection]));
        }
        if ($existing === $asset) {
            return $this->changed($asset);
        }
        $this->persistenceManager->persistAll();
        return AssetCreated::uploaded($this->asset($this->assetResolver->neosProxy($asset)));
    }

    #[Operation(
        path: '/assets/{assetSourceId}/{assetId}/resource',
        method: 'POST',
        summary: 'Replace the file of an asset',
        description: 'Replaces the file of an asset of neos and keeps the asset: its ID, metadata and usages, as the Neos media browser does. The body is multipart/form-data, not JSON (the document can\'t describe it): resource the new file (required), keepOriginalFilename (true/false, false if left out) to keep the current filename with the new file\'s extension, generateRedirects (true/false, true if left out) to redirect the old file\'s URI to the new one if Neos.RedirectHandler is installed. An image, audio or video can only be replaced by a file of the same kind, an image variant has no file of its own (replace its original image): both 422s, as are assets of other sources. A missing file or a malformed field is a 400.',
        operationId: 'replaceAssetResource',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::ASSETS_UPDATE],
        ],
    )]
    public function replaceResource(AssetSourceId $assetSourceId, AssetId $assetId, ServerRequestInterface $request): Asset|NotFound|BadRequest|UnprocessableContent
    {
        $form = MultipartForm::of($request);
        $file = $form->file('resource');
        if ($file === null) {
            return BadRequest::because($form->uploadError('resource'));
        }
        $keepOriginalFilename = $form->bool('keepOriginalFilename', false);
        $generateRedirects = $form->bool('generateRedirects', true);
        if ($keepOriginalFilename === null || $generateRedirects === null) {
            return BadRequest::because('keepOriginalFilename and generateRedirects must be true or false');
        }
        $asset = $this->assetResolver->findAssetToChange($assetSourceId, $assetId);
        if (!$asset instanceof Model\Asset) {
            return $asset;
        }
        if ($asset instanceof Model\ImageVariant) {
            return UnprocessableContent::because('An image variant has no file of its own, replace the file of its original image (originalAssetId)');
        }
        // a PDF in place of an image would break every rendering of it. Flow takes the media type from the filename
        $currentType = MediaTypes::parseMediaType($asset->getMediaType())['type'];
        $newMediaType = MediaTypes::getMediaTypeFromFilename($file->getClientFilename() ?? '');
        if (in_array($currentType, ['image', 'audio', 'video'], true) && MediaTypes::parseMediaType($newMediaType)['type'] !== $currentType) {
            return UnprocessableContent::because(sprintf('An asset of the type %s can only be replaced by another %s file, not by %s', $currentType, $currentType, $newMediaType));
        }
        $this->assetService->replaceAssetResource($asset, $this->importResource($file), [
            'keepOriginalFilename' => $keepOriginalFilename,
            'generateRedirects' => $generateRedirects,
        ]);
        $this->persistenceManager->persistAll();
        return $this->asset($this->assetResolver->neosProxy($asset));
    }

    #[Operation(
        path: '/assets/{assetSourceId}/{assetId}/import',
        method: 'POST',
        summary: 'Import a remote asset',
        description: 'Imports the asset of a remote asset source into neos, as the Neos media browser does when it is used: its file, and its IPTC title, caption and copyright notice if it has them. The response is the asset in neos (its localAssetId), a 201, or a 200 if it was imported before. The assets of neos are a 422, there is nothing to import.',
        operationId: 'importAsset',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::ASSETS_CREATE],
        ],
    )]
    public function import(AssetSourceId $assetSourceId, AssetId $assetId): AssetCreated|Asset|NotFound|UnprocessableContent
    {
        $proxy = $this->assetResolver->findAsset($assetSourceId, $assetId);
        if ($proxy instanceof NotFound) {
            return $proxy;
        }
        if (!$proxy instanceof HasRemoteOriginalInterface) {
            return UnprocessableContent::because(sprintf('The assets of the asset source %s are local, there is nothing to import', $assetSourceId->value));
        }
        $importedBefore = $proxy->getLocalAssetIdentifier() !== null;
        try {
            $importedAsset = $this->assetSourceService->importAsset($assetSourceId->value, $assetId->value);
        } catch (AssetSourceServiceException $exception) {
            return UnprocessableContent::because($exception->getMessage());
        }
        $this->persistenceManager->persistAll();
        $localAsset = $this->assetRepository->findByIdentifier($importedAsset->getLocalAssetIdentifier());
        if (!$localAsset instanceof Model\AssetInterface) {
            throw new \RuntimeException(sprintf('The imported asset %s wasn\'t found', $importedAsset->getLocalAssetIdentifier()), 1791800002);
        }
        $asset = $this->asset($this->assetResolver->neosProxy($localAsset));
        return $importedBefore ? $asset : AssetCreated::imported($asset);
    }

    #[Operation(
        path: '/assets/{assetSourceId}/{assetId}/variants',
        method: 'POST',
        summary: 'Create an image variant',
        description: 'Creates a variant of an image of neos that shows a part of it, crop in pixels of the image, and returns it as an asset of neos: its id is what a node property refers to. Variants are made from the image, not from other variants: for a variant, use its originalAssetId (else a 422). Other assets than images and a crop that isn\'t inside the image are 422s, as are assets of other sources.',
        operationId: 'createAssetVariant',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::ASSETS_CREATE],
        ],
    )]
    public function createVariant(
        AssetSourceId $assetSourceId,
        AssetId $assetId,
        #[RequestBody(description: 'The crop')] AssetVariantCreate $newVariant,
    ): AssetCreated|NotFound|UnprocessableContent {
        $image = $this->assetResolver->findAssetToChange($assetSourceId, $assetId);
        if (!$image instanceof Model\Asset) {
            return $image;
        }
        if ($image instanceof Model\ImageVariant) {
            return UnprocessableContent::because('The asset is an image variant, create the variant from its original image (originalAssetId)');
        }
        if (!$image instanceof Model\Image) {
            return UnprocessableContent::because('Only images have variants');
        }
        $crop = $newVariant->crop;
        if ($crop->x + $crop->width > $image->getWidth() || $crop->y + $crop->height > $image->getHeight()) {
            return UnprocessableContent::because(sprintf('The crop isn\'t inside the image of %d×%d pixels', $image->getWidth(), $image->getHeight()));
        }
        $variant = new Model\ImageVariant($image);
        $newVariant->applyTo($variant);
        $this->assetRepository->add($variant);
        $this->persistenceManager->persistAll();
        return AssetCreated::variant($this->asset($this->assetResolver->neosProxy($variant)));
    }

    /**
     * The node of the usage in its workspace and dimension space point, null if the account may not read it
     */
    private function usage(AssetUsageReference $reference): ?AssetUsage
    {
        $subgraph = $this->contentSubgraphs->find(
            $reference->getContentRepositoryId(),
            $reference->getWorkspaceName(),
            $reference->getOriginDimensionSpacePoint()->toDimensionSpacePoint(),
            excludeDisabled: false,
        );
        $node = $subgraph?->findNodeById($reference->getNodeAggregateId());
        if ($subgraph === null || $node === null) {
            return null;
        }
        $documentNode = $subgraph->findClosestNode($node->aggregateId, FindClosestNodeFilter::create(nodeTypes: NodeTypeNameFactory::NAME_DOCUMENT));
        return new AssetUsage(
            Node::from($node, $this->nodeSerializer, false),
            $documentNode !== null ? Node::from($documentNode, $this->nodeSerializer, false) : null,
        );
    }

    private function importResource(UploadedFileInterface $file): PersistentResource
    {
        $resource = $this->resourceManager->importResource($file->getStream()->detach());
        $resource->setFilename($file->getClientFilename() ?? 'upload');
        return $resource;
    }

    private static function sha1(UploadedFileInterface $file): string
    {
        $stream = $file->getStream();
        $stream->rewind();
        $hash = hash_init('sha1');
        while (!$stream->eof()) {
            hash_update($hash, $stream->read(1048576));
        }
        $stream->rewind();
        return hash_final($hash);
    }

    /**
     * Saves the changed asset of neos (Neos flushes the content caches of its usages) and returns it
     */
    private function changed(Model\Asset $asset): Asset
    {
        $this->assetRepository->update($asset);
        $this->persistenceManager->persistAll();
        return $this->asset($this->assetResolver->neosProxy($asset));
    }

    private function asset(AssetProxyInterface $proxy): Asset
    {
        return Asset::from($proxy, $this->persistenceManager);
    }

    /**
     * @template T of object
     * @param list<T> $objects
     * @return list<T> each object once
     */
    private static function distinct(array $objects): array
    {
        $distinct = [];
        foreach ($objects as $object) {
            $distinct[spl_object_id($object)] = $object;
        }
        return array_values($distinct);
    }
}
