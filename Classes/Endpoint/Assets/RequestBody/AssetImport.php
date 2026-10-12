<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\Assets\RequestBody;

use Neos\Api\Endpoint\Assets\Schema\AssetId;
use Neos\Api\Endpoint\Assets\Schema\AssetSourceId;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * A remote asset to import into neos
 *
 * No applyTo(): Neos' AssetSourceService creates the asset
 */
final readonly class AssetImport implements ProvidesSchema
{
    /**
     * @param AssetSourceId $assetSourceId a remote asset source, not neos
     */
    public function __construct(
        public AssetSourceId $assetSourceId,
        public AssetId $assetId,
    ) {
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
