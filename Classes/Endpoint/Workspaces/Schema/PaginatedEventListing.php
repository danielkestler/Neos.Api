<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\Workspaces\Schema;

use Neos\Api\Shared\Schema\CursorListingLinks;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * A page of events, as JSON:API's top level: the events in data, the next page in links. No meta: the event store
 * can't count the events without reading them all
 */
final readonly class PaginatedEventListing implements ProvidesSchema
{
    public function __construct(
        public EventList $data,
        public CursorListingLinks $links,
    ) {
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
