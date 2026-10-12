<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\AssetCollections\Schema;

use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Media\Domain\Model;
use Neos\Media\Domain\Repository\AssetRepository;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * A collection of the media library, a group of assets: an asset may be in several
 */
final readonly class AssetCollection implements ProvidesSchema
{
    /**
     * @param int $assetCount how many assets are in the collection
     */
    public function __construct(
        public AssetCollectionId $id,
        public AssetCollectionTitle $title,
        public int $assetCount,
    ) {
    }

    public static function from(Model\AssetCollection $assetCollection, PersistenceManagerInterface $persistenceManager, AssetRepository $assetRepository): self
    {
        return new self(
            AssetCollectionId::fromString($persistenceManager->getIdentifierByObject($assetCollection)),
            AssetCollectionTitle::fromString($assetCollection->getTitle()),
            $assetRepository->countByAssetCollection($assetCollection),
        );
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
