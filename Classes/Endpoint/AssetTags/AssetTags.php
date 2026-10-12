<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\AssetTags;

use Neos\Api\Endpoint\AssetTags\RequestBody\AssetTagCreate;
use Neos\Api\Endpoint\AssetTags\RequestBody\AssetTagUpdate;
use Neos\Api\Endpoint\AssetTags\Response\AssetTagCreated;
use Neos\Api\Endpoint\AssetTags\Schema\AssetTag;
use Neos\Api\Endpoint\AssetTags\Schema\AssetTagId;
use Neos\Api\Endpoint\AssetTags\Schema\AssetTagList;
use Neos\Api\Endpoint\AssetTags\Schema\AssetTagListing;
use Neos\Api\Security\ApiAuthContextProvider;
use Neos\Api\Security\ApiScopes;
use Neos\Api\Shared\Response\NotFound;
use Neos\Api\Shared\Response\UnprocessableContent;
use Neos\Api\Shared\Schema\ListingMeta;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Media\Domain\Model;
use Neos\Media\Domain\Repository\AssetRepository;
use Neos\Media\Domain\Repository\TagRepository;
use Neos\OpenApi\Attributes\Operation;
use Neos\OpenApi\Attributes\RequestBody;
use Neos\Utility\ObjectAccess;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The tags of the media library, a tree
 */
final readonly class AssetTags
{
    public function __construct(
        private TagRepository $tagRepository,
        private AssetRepository $assetRepository,
        private PersistenceManagerInterface $persistenceManager,
    ) {
    }

    #[Operation(
        path: '/assettags',
        method: 'GET',
        summary: 'List the asset tags',
        description: 'All tags of the media library as a tree: the tags at the root in data, each with its child tags in children, sorted by label. meta.total counts all tags. The tags are the account\'s, as Neos\' media privileges allow.',
        operationId: 'listAssetTags',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::ASSETTAGS_READ],
        ],
    )]
    public function list(): AssetTagListing
    {
        /** @var list<Model\Tag> $tags */
        $tags = $this->tagRepository->findAll()->toArray();
        return new AssetTagListing(
            new AssetTagList(...array_map(
                $this->assetTag(...),
                array_values(array_filter($tags, static fn (Model\Tag $tag) => $tag->getParent() === null)),
            )),
            new ListingMeta(count($tags)),
        );
    }

    #[Operation(
        path: '/assettags',
        method: 'POST',
        summary: 'Create an asset tag',
        description: 'Creates a tag, at the root or in parentId. An unknown parent is a 422.',
        operationId: 'createAssetTag',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::ASSETTAGS_CREATE],
        ],
    )]
    public function create(
        #[RequestBody(description: 'The tag')] AssetTagCreate $newTag,
    ): AssetTagCreated|UnprocessableContent {
        $parent = null;
        if ($newTag->parentId !== null) {
            $parent = $this->findTag($newTag->parentId);
            if ($parent === null) {
                return UnprocessableContent::because(sprintf('There is no asset tag with the ID %s', $newTag->parentId->value));
            }
        }
        $tag = new Model\Tag($newTag->label->value);
        $newTag->applyTo($tag, $parent);
        $this->tagRepository->add($tag);
        $this->persistenceManager->persistAll();
        return new AssetTagCreated($this->assetTag($tag));
    }

    #[Operation(
        path: '/assettags/{assetTagId}',
        method: 'GET',
        summary: 'Get an asset tag',
        description: 'The tag with its child tags.',
        operationId: 'getAssetTag',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::ASSETTAGS_READ],
        ],
    )]
    public function get(AssetTagId $assetTagId): AssetTag|NotFound
    {
        $tag = $this->findTag($assetTagId);
        return $tag !== null ? $this->assetTag($tag) : self::notFound($assetTagId);
    }

    #[Operation(
        path: '/assettags/{assetTagId}',
        method: 'PATCH',
        summary: 'Change an asset tag',
        description: 'Renames the tag and moves it into another tag (parentId) or to the root (parentId null), what is left out stays as it is. An unknown parent, the tag itself or one of its descendants as parent are 422s.',
        operationId: 'updateAssetTag',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::ASSETTAGS_UPDATE],
        ],
    )]
    public function update(
        AssetTagId $assetTagId,
        #[RequestBody(description: 'The changes')] AssetTagUpdate $patch,
        ServerRequestInterface $request,
    ): AssetTag|NotFound|UnprocessableContent {
        $tag = $this->findTag($assetTagId);
        if ($tag === null) {
            return self::notFound($assetTagId);
        }
        // AssetTagUpdate can't tell a parentId null from one left out
        $body = json_decode((string)$request->getBody(), true, flags: JSON_THROW_ON_ERROR);
        if (array_key_exists('parentId', $body)) {
            if ($patch->parentId === null) {
                // to the root: Tag::setParent() takes no null. Not removed from the old parent's children, they remove
                // orphans (orphanRemoval), which would delete it. The children are read from the parent column again
                ObjectAccess::setProperty($tag, 'parent', null, true);
            } else {
                $parent = $this->findTag($patch->parentId);
                if ($parent === null) {
                    return UnprocessableContent::because(sprintf('There is no asset tag with the ID %s', $patch->parentId->value));
                }
                for ($ancestor = $parent; $ancestor !== null; $ancestor = $ancestor->getParent()) {
                    if ($ancestor === $tag) {
                        return UnprocessableContent::because('An asset tag can\'t be moved into itself or one of its descendants');
                    }
                }
                if ($tag->getParent() !== $parent) {
                    $tag->setParent($parent);
                }
            }
        }
        $patch->applyTo($tag);
        $this->tagRepository->update($tag);
        $this->persistenceManager->persistAll();
        return $this->assetTag($tag);
    }

    #[Operation(
        path: '/assettags/{assetTagId}',
        method: 'DELETE',
        summary: 'Delete an asset tag',
        description: 'Deletes the tag with its descendants, as the Neos media browser does. The assets stay, without these tags.',
        operationId: 'deleteAssetTag',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::ASSETTAGS_DELETE],
        ],
    )]
    public function delete(AssetTagId $assetTagId): NotFound|null
    {
        $tag = $this->findTag($assetTagId);
        if ($tag === null) {
            return self::notFound($assetTagId);
        }
        // deepest first: the children remove orphans (orphanRemoval) anyway, but not from the assets
        foreach (array_reverse(self::subtree($tag)) as $removed) {
            foreach ($this->assetRepository->findByTag($removed) as $asset) {
                $asset->removeTag($removed);
                $this->assetRepository->update($asset);
            }
            $this->tagRepository->remove($removed);
        }
        $this->persistenceManager->persistAll();
        return null;
    }

    private function findTag(AssetTagId $assetTagId): ?Model\Tag
    {
        $tag = $this->tagRepository->findByIdentifier($assetTagId->value);
        return $tag instanceof Model\Tag ? $tag : null;
    }

    private function assetTag(Model\Tag $tag): AssetTag
    {
        return AssetTag::from($tag, $this->persistenceManager, $this->assetRepository);
    }

    /**
     * The tag and its descendants, parents before their children
     *
     * @return list<Model\Tag>
     */
    private static function subtree(Model\Tag $tag): array
    {
        $tags = [$tag];
        foreach ($tag->getChildren() as $child) {
            array_push($tags, ...self::subtree($child));
        }
        return $tags;
    }

    private static function notFound(AssetTagId $assetTagId): NotFound
    {
        return NotFound::because(sprintf('There is no asset tag with the ID %s', $assetTagId->value));
    }
}
