<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\NodeTypes\Schema;

use Neos\Api\Shared\Schema\ListingMeta;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * The node types of a content repository, as JSON:API's top level: the items in data, the total in meta. Not paged, so no links
 */
final readonly class NodeTypeListing implements ProvidesSchema
{
    public function __construct(
        public NodeTypeList $data,
        public ListingMeta $meta,
    ) {
    }

    public static function of(NodeTypeList $data): self
    {
        return new self($data, new ListingMeta(count($data->nodeTypes)));
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
