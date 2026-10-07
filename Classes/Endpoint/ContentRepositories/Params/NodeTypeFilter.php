<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\ContentRepositories\Params;

use Neos\Api\Endpoint\ContentRepositories\Schema\NodeTypeName;
use Neos\JsonSchema\ObjectSchema;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\Support\ObjectProperties;

/**
 * Which node types to list, as JSON:API's filter family: ?filter[superType]=Neos.Neos:Document
 */
final readonly class NodeTypeFilter implements ProvidesSchema
{
    public function __construct(
        public NodeTypeName|null $superType = null,
    ) {
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= ObjectSchema::create(
            description: 'Which node types to list',
            properties: ObjectProperties::create(
                superType: NodeTypeName::schema(),
            ),
            additionalProperties: false,
        );
    }
}
