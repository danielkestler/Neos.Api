<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\Assets;

use Neos\Api\Endpoint\Assets\Schema\AssetId;
use Neos\Api\Endpoint\Assets\Schema\AssetSourceId;
use Neos\Api\Shared\Response\NotFound;
use Neos\Api\Shared\Response\UnprocessableContent;
use Neos\Flow\Annotations as Flow;
use Neos\Media\Domain\Model;
use Neos\Media\Domain\Model\AssetSource\AssetNotFoundExceptionInterface;
use Neos\Media\Domain\Model\AssetSource\AssetProxy\AssetProxyInterface;
use Neos\Media\Domain\Model\AssetSource\AssetProxyRepositoryInterface;
use Neos\Media\Domain\Model\AssetSource\AssetSourceInterface;
use Neos\Media\Domain\Model\AssetSource\Neos\NeosAssetProxy;
use Neos\Media\Domain\Model\AssetSource\Neos\NeosAssetSource;
use Neos\Media\Domain\Model\AssetSource\SupportsCollectionsInterface;
use Neos\Media\Domain\Service\AssetSourceService;

/**
 * Asset source and asset ID → the asset source's repository or asset, or the 404; for changes the asset of neos, the
 * only source whose assets can be changed and that takes uploads (isNeos())
 *
 * A source keeps one repository whose filters are state (the neos one swaps the repository it reads from by type, so
 * an image filter hides documents from getAssetProxy()): they are reset each time, the caller sets its own
 */
#[Flow\Scope('singleton')]
final readonly class AssetResolver
{
    /**
     * The asset source of the media library of Neos
     */
    public const string NEOS = 'neos';

    public function __construct(
        private AssetSourceService $assetSourceService,
    ) {
    }

    public function findAssetSource(AssetSourceId $assetSourceId): ?AssetSourceInterface
    {
        return $this->assetSourceService->getAssetSources()[$assetSourceId->value] ?? null;
    }

    /**
     * Whether the source is neos, whose assets can be changed and which takes uploads: other sources are read-only
     * here, their assets are imported into neos first
     */
    public static function isNeos(AssetSourceInterface $assetSource): bool
    {
        return $assetSource->getIdentifier() === self::NEOS && $assetSource instanceof NeosAssetSource && !$assetSource->isReadOnly();
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
        $asset = $proxy instanceof NeosAssetProxy && self::isNeos($proxy->getAssetSource()) ? $proxy->getAsset() : null;
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
        $neos = $this->assetSourceService->getAssetSources()[self::NEOS] ?? null;
        if (!$neos instanceof NeosAssetSource) {
            throw new \RuntimeException('There is no asset source neos', 1791800001);
        }
        return new NeosAssetProxy($asset, $neos);
    }

    public static function noAssetSource(AssetSourceId $assetSourceId): NotFound
    {
        return NotFound::because(sprintf('There is no asset source %s', $assetSourceId->value));
    }
}
