<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\Nodes\Schema;

use Neos\Api\Shared\Schema\ListingMeta;
use Neos\JsonSchema\ObjectSchema;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\Support\ObjectProperties;

/**
 * Nodes, all of them, as JSON:API's top level: the nodes in data, the total in meta
 *
 * The schema is written out and data typed iterable, as Node's children, see NodeList
 */
final readonly class NodeListing implements ProvidesSchema
{
    /**
     * @param NodeList $data typed iterable, not NodeList, see there
     */
    public function __construct(
        public iterable $data,
        public ListingMeta $meta,
    ) {
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= ObjectSchema::create(
            description: 'Nodes with their total',
            properties: ObjectProperties::create(
                data: NodeList::schema(),
                meta: ListingMeta::schema(),
            ),
            additionalProperties: false,
            required: ['data', 'meta'],
        );
    }
}
