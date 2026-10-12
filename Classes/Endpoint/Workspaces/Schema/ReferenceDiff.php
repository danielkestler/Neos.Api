<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\Workspaces\Schema;

use Neos\Api\Endpoint\Nodes\Schema\NodeAggregateId;
use Neos\JsonSchema\ArraySchema;
use Neos\JsonSchema\ObjectSchema;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\StringSchema;
use Neos\JsonSchema\Support\ObjectProperties;

/**
 * A reference changed by a workspace, compared to its base workspace: the referenced nodes
 *
 * The schema is written out as Node's tags: lists of value objects that are plain strings here
 */
final readonly class ReferenceDiff implements ProvidesSchema
{
    /**
     * @param list<string> $old the referenced node aggregate ids in the base workspace, in their order
     * @param list<string> $new the ones in the workspace, in their order
     */
    public function __construct(
        public string $name,
        public array $old,
        public array $new,
    ) {
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= ObjectSchema::create(
            description: 'A reference changed by a workspace, compared to its base workspace',
            properties: ObjectProperties::create(
                name: StringSchema::create(description: 'The name of the reference', examples: ['relatedPages']),
                old: ArraySchema::create(
                    description: 'The referenced nodes in the base workspace, in their order, empty if none or the node doesn\'t exist there',
                    items: NodeAggregateId::schema(),
                ),
                new: ArraySchema::create(
                    description: 'The referenced nodes in the workspace, in their order, empty if none',
                    items: NodeAggregateId::schema(),
                ),
            ),
            additionalProperties: false,
            required: ['name', 'old', 'new'],
        );
    }
}
