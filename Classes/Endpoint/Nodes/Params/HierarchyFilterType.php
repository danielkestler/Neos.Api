<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\Nodes\Params;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\StringSchema;

/**
 * What the node of a HierarchyFilter is to the nodes listed
 */
enum HierarchyFilterType: string implements ProvidesSchema
{
    case PARENT = 'parent';
    case ANCESTOR = 'ancestor';

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= StringSchema::create(
            description: 'parent for the direct child nodes, ancestor for all nodes below it',
            enum: array_map(static fn (self $case) => $case->value, self::cases()),
        );
    }
}
