<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\AssetTags;

use Doctrine\ORM\EntityManagerInterface;
use Neos\Api\Endpoint\AssetTags\Schema\AssetTagId;
use Neos\Api\Shared\Response\UnprocessableContent;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Flow\Reflection\ReflectionService;
use Neos\Media\Domain\Model;
use Neos\Media\Domain\Model\AssetVariantInterface;
use Neos\Media\Domain\Repository\TagRepository;

/**
 * Finds the tags of the media library for every endpoint that refers to them, as the account may see them (Neos'
 * media privileges, Doctrine filters), and counts their assets
 */
#[Flow\Scope('singleton')]
final readonly class AssetTagFinder
{
    public function __construct(
        private TagRepository $tagRepository,
        private EntityManagerInterface $entityManager,
        private ReflectionService $reflectionService,
        private PersistenceManagerInterface $persistenceManager,
    ) {
    }

    public function find(AssetTagId $assetTagId): ?Model\Tag
    {
        $tag = $this->tagRepository->findByIdentifier($assetTagId->value);
        return $tag instanceof Model\Tag ? $tag : null;
    }

    /**
     * The tags, each once, or the 422 for the first unknown one
     *
     * @param iterable<AssetTagId> $assetTagIds
     * @return list<Model\Tag>|UnprocessableContent
     */
    public function findAll(iterable $assetTagIds): array|UnprocessableContent
    {
        $tags = [];
        foreach ($assetTagIds as $assetTagId) {
            $tag = $this->find($assetTagId);
            if ($tag === null) {
                return UnprocessableContent::because(self::noTag($assetTagId));
            }
            $tags[spl_object_id($tag)] = $tag;
        }
        return array_values($tags);
    }

    /**
     * All tags the account may see with their asset counts, the tree that AssetTag is made from
     */
    public function tree(): AssetTagTree
    {
        /** @var list<Model\Tag> $tags */
        $tags = $this->tagRepository->findAll()->toArray();
        return new AssetTagTree($tags, $this->assetCounts(), $this->persistenceManager);
    }

    /**
     * How many assets have each tag (image variants aren't counted, as in Neos' lists), by tag ID
     *
     * One DQL query instead of TagRepository's native SQL, so the media privileges apply to the assets counted
     *
     * @return array<string, int>
     */
    private function assetCounts(): array
    {
        $variants = array_values(array_filter(
            $this->reflectionService->getAllImplementationClassNamesForInterface(AssetVariantInterface::class),
            fn (string $className) => $this->reflectionService->isClassAnnotatedWith($className, Flow\Entity::class),
        ));
        $rows = $this->entityManager->createQuery(sprintf(
            'SELECT t.Persistence_Object_Identifier AS id, COUNT(a) AS assetCount FROM %s a JOIN a.tags t%s GROUP BY t.Persistence_Object_Identifier',
            Model\Asset::class,
            $variants !== [] ? ' WHERE a NOT INSTANCE OF (' . implode(', ', $variants) . ')' : '',
        ))->getArrayResult();
        return array_map(intval(...), array_column($rows, 'assetCount', 'id'));
    }

    public static function noTag(AssetTagId $assetTagId): string
    {
        return sprintf('There is no asset tag with the ID %s', $assetTagId->value);
    }
}
