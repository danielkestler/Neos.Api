<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\Nodes\RequestBody;

use Neos\Api\Endpoint\Nodes\Schema\NodeAggregateId;
use Neos\Api\Endpoint\NodeTypes\Schema\NodeTypeName;
use Neos\JsonSchema\Nullable;
use Neos\JsonSchema\ObjectSchema;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\Support\ObjectProperties;

/**
 * A node to create below a parent
 *
 * There is no applyTo(): a node is created by a command, which the endpoint builds. The schema is written out,
 * properties is a map, which schematic can't discover from an array
 */
final readonly class NodeCreate implements ProvidesSchema
{
    /**
     * @param array<string, mixed> $properties
     */
    public function __construct(
        public NodeTypeName $nodeType,
        public NodeAggregateId $parentNodeAggregateId,
        public NodeAggregateId|null $succeedingSiblingNodeAggregateId = null,
        public NodeAggregateId|null $nodeAggregateId = null,
        public array $properties = [],
    ) {
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= ObjectSchema::create(
            description: 'A node to create below a parent',
            properties: ObjectProperties::create(
                nodeType: NodeTypeName::schema(),
                parentNodeAggregateId: NodeAggregateId::schema(),
                succeedingSiblingNodeAggregateId: Nullable::wrap(NodeAggregateId::schema()),
                nodeAggregateId: Nullable::wrap(NodeAggregateId::schema()),
                properties: ObjectSchema::create(
                    description: 'The properties to set, as for updateNodeProperties, over the node type\'s defaults. A document without uriPathSegment gets one from its title, as in the Neos backend',
                    examples: [['title' => 'About us']],
                    additionalProperties: true,
                ),
            ),
            additionalProperties: false,
            required: ['nodeType', 'parentNodeAggregateId'],
        );
    }
}
