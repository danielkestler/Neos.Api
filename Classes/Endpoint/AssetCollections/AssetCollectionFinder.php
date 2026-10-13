<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\AssetCollections;

use Doctrine\ORM\EntityManagerInterface;
use Neos\Api\Endpoint\AssetCollections\Schema\AssetCollection;
use Neos\Api\Endpoint\AssetCollections\Schema\AssetCollectionId;
use Neos\Api\Shared\Response\UnprocessableContent;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Flow\Reflection\ReflectionService;
use Neos\Media\Domain\Model;
use Neos\Media\Domain\Model\AssetVariantInterface;
use Neos\Media\Domain\Repository\AssetCollectionRepository;

/**
 * Finds the collections of the media library for every endpoint that refers to them, as the account may see them
 * (Neos' media privileges, Doctrine filters), and counts their assets
 */
#[Flow\Scope('singleton')]
final readonly class AssetCollectionFinder
{
    public function __construct(
        private AssetCollectionRepository $assetCollectionRepository,
        private EntityManagerInterface $entityManager,
        private ReflectionService $reflectionService,
        private PersistenceManagerInterface $persistenceManager,
    ) {
    }

    public function find(AssetCollectionId $assetCollectionId): ?Model\AssetCollection
    {
        $assetCollection = $this->assetCollectionRepository->findByIdentifier($assetCollectionId->value);
        return $assetCollection instanceof Model\AssetCollection ? $assetCollection : null;
    }

    /**
     * The collections, each once, or the 422 for the first unknown one
     *
     * @param iterable<AssetCollectionId> $assetCollectionIds
     * @return list<Model\AssetCollection>|UnprocessableContent
     */
    public function findAll(iterable $assetCollectionIds): array|UnprocessableContent
    {
        $assetCollections = [];
        foreach ($assetCollectionIds as $assetCollectionId) {
            $assetCollection = $this->find($assetCollectionId);
            if ($assetCollection === null) {
                return UnprocessableContent::because(self::noCollection($assetCollectionId));
            }
            $assetCollections[spl_object_id($assetCollection)] = $assetCollection;
        }
        return array_values($assetCollections);
    }

    /**
     * The collections as AssetCollection, with one query for all their asset counts
     *
     * @param iterable<Model\AssetCollection> $assetCollections
     * @return list<AssetCollection>
     */
    public function read(iterable $assetCollections): array
    {
        $assetCounts = $this->assetCounts();
        $read = [];
        foreach ($assetCollections as $assetCollection) {
            $read[] = AssetCollection::from($assetCollection, $this->persistenceManager, $assetCounts[$this->persistenceManager->getIdentifierByObject($assetCollection)] ?? 0);
        }
        return $read;
    }

    /**
     * How many assets are in each collection (image variants aren't counted, as in Neos' lists), by collection ID
     *
     * One DQL query instead of AssetRepository's native SQL, so the media privileges apply to the assets counted
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
            'SELECT c.Persistence_Object_Identifier AS id, COUNT(a) AS assetCount FROM %s a JOIN a.assetCollections c%s GROUP BY c.Persistence_Object_Identifier',
            Model\Asset::class,
            $variants !== [] ? ' WHERE a NOT INSTANCE OF (' . implode(', ', $variants) . ')' : '',
        ))->getArrayResult();
        return array_map(intval(...), array_column($rows, 'assetCount', 'id'));
    }

    public static function noCollection(AssetCollectionId $assetCollectionId): string
    {
        return sprintf('There is no asset collection with the ID %s', $assetCollectionId->value);
    }
}
