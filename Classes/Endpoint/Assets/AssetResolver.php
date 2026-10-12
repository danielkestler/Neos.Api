<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\Assets;

use Neos\Api\Endpoint\Assets\Schema\AssetId;
use Neos\Api\Endpoint\Assets\Schema\AssetSourceId;
use Neos\Api\Shared\Response\NotFound;
use Neos\Api\Shared\Response\UnprocessableContent;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Media\Domain\Model;
use Neos\Media\Domain\Model\AssetSource\AssetNotFoundExceptionInterface;
use Neos\Media\Domain\Model\AssetSource\AssetProxy\AssetProxyInterface;
use Neos\Media\Domain\Model\AssetSource\AssetProxyRepositoryInterface;
use Neos\Media\Domain\Model\AssetSource\AssetSourceInterface;
use Neos\Media\Domain\Model\AssetSource\Neos\NeosAssetProxy;
use Neos\Media\Domain\Model\AssetSource\SupportsCollectionsInterface;
use Neos\Media\Domain\Service\AssetSourceService;

/**
 * Asset source and asset ID → the asset source's repository or asset, or the 404; for changes the asset of neos, the
 * only source whose assets can be changed
 *
 * A source keeps one repository whose filters are state (the neos one swaps the repository it reads from by type, so
 * an image filter hides documents from getAssetProxy()): they are reset each time, the caller sets its own
 */
#[Flow\Scope('singleton')]
final readonly class AssetResolver
{
    public function __construct(
        private AssetSourceService $assetSourceService,
        private PersistenceManagerInterface $persistenceManager,
    ) {
    }

    public function findAssetSource(AssetSourceId $assetSourceId): ?AssetSourceInterface
    {
        return $this->assetSourceService->getAssetSources()[$assetSourceId->value] ?? null;
    }

    public function repository(AssetSourceInterface $assetSource): AssetProxyRepositoryInterface
    {
        $repository = $assetSource->getAssetProxyRepository();
        $repository->filterByType(null);
        if ($repository instanceof SupportsCollectionsInterface) {
            $repository->filterByCollection(null);
        }
        return $repository;
    }

    public function findAsset(AssetSourceId $assetSourceId, AssetId $assetId): AssetProxyInterface|NotFound
    {
        $assetSource = $this->findAssetSource($assetSourceId);
        if ($assetSource === null) {
            return self::noAssetSource($assetSourceId);
        }
        try {
            return $this->repository($assetSource)->getAssetProxy($assetId->value);
        } catch (AssetNotFoundExceptionInterface) {
            return NotFound::because(sprintf('There is no asset with the ID %s in the asset source %s', $assetId->value, $assetSourceId->value));
        }
    }

    /**
     * The asset to change: of neos, assets of other sources can only be read (422), they are imported first
     */
    public function findAssetToChange(AssetSourceId $assetSourceId, AssetId $assetId): Model\Asset|NotFound|UnprocessableContent
    {
        $proxy = $this->findAsset($assetSourceId, $assetId);
        if ($proxy instanceof NotFound) {
            return $proxy;
        }
        $asset = $proxy instanceof NeosAssetProxy && !$proxy->getAssetSource()->isReadOnly() ? $proxy->getAsset() : null;
        if (!$asset instanceof Model\Asset) {
            return UnprocessableContent::because(sprintf('The assets of the asset source %s can\'t be changed, import the asset into neos first', $assetSourceId->value));
        }
        return $asset;
    }

    /**
     * An asset of neos as its asset proxy, which is what Asset is made from
     */
    public function neosProxy(Model\AssetInterface $asset): AssetProxyInterface
    {
        $proxy = $this->findAsset(AssetSourceId::fromString('neos'), AssetId::fromString($this->persistenceManager->getIdentifierByObject($asset)));
        if (!$proxy instanceof AssetProxyInterface) {
            throw new \RuntimeException(sprintf('The asset %s isn\'t in neos', $this->persistenceManager->getIdentifierByObject($asset)), 1791800001);
        }
        return $proxy;
    }

    public static function noAssetSource(AssetSourceId $assetSourceId): NotFound
    {
        return NotFound::because(sprintf('There is no asset source %s', $assetSourceId->value));
    }
}
