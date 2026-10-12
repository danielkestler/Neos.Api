<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\Assets\Schema;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * The tags of an asset
 *
 * @implements \IteratorAggregate<AssetTagReference>
 */
final readonly class AssetTagReferenceList implements ProvidesSchema, \IteratorAggregate
{
    /**
     * @var list<AssetTagReference>
     */
    public array $assetTags;

    public function __construct(AssetTagReference ...$assetTags)
    {
        $this->assetTags = array_values($assetTags);
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }

    public function getIterator(): \Traversable
    {
        yield from $this->assetTags;
    }
}
