<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\Nodes\Params;

use Neos\Api\Endpoint\Nodes\Schema\NodeAggregateId;
use Neos\JsonSchema\ObjectSchema;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\StringSchema;
use Neos\JsonSchema\Support\ObjectProperties;

/**
 * Which nodes to list, as JSON:API's filter family: ?filter[parent]=…&filter[nodeType]=Neos.Neos:Document
 *
 * At most one of parent, ancestor and referencing says where to look, each is a query of its own in the content
 * repository, so they can't be combined. Without any, it's the nodes below the site node of the default site. The
 * workspace and dimension space point are the operation's own parameters, they apply to every node
 */
final readonly class NodeFilter implements ProvidesSchema
{
    public function __construct(
        public NodeAggregateId|null $parent = null,
        public NodeAggregateId|null $ancestor = null,
        public NodeAggregateId|null $referencing = null,
        public string|null $referenceName = null,
        public string|null $nodeType = null,
        public string|null $search = null,
        public string|null $property = null,
    ) {
    }

    /**
     * @return list<NodeAggregateId>
     */
    public function entryPoints(): array
    {
        return array_values(array_filter([$this->parent, $this->ancestor, $this->referencing]));
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= ObjectSchema::create(
            description: 'Which nodes to list. At most one of filter[parent], filter[ancestor] and filter[referencing] (the aggregate id of a node in the workspace and dimension space point), without any all nodes below the site node of the content repository\'s default site. The other members narrow them down',
            properties: ObjectProperties::create(
                parent: NodeAggregateId::schema(),
                ancestor: NodeAggregateId::schema(),
                referencing: NodeAggregateId::schema(),
                referenceName: StringSchema::create(description: 'With filter[referencing], only the references of this name', examples: ['relatedPages'], minLength: 1),
                nodeType: StringSchema::create(description: 'Only nodes of these node types or ones inheriting from them, comma-separated, a ! in front excludes a type and the ones inheriting from it', examples: ['Neos.Neos:Document,!Neos.Neos:Shortcut'], minLength: 1),
                search: StringSchema::create(description: 'Only nodes with a property containing this text', examples: ['neos'], minLength: 1),
                property: StringSchema::create(description: 'Only nodes whose properties match, in the content repository\'s syntax: comparisons like title = \'Home\', title != \'Home\', title *= \'contains\', title ^= \'starts\', title $= \'ends\', count > 3 (also >=, <, <=, values are strings in quotes, numbers or true/false), combined with AND, OR, NOT and parentheses', examples: ['title *= \'Neos\' AND NOT (hideInMenu = true)'], minLength: 1),
            ),
            additionalProperties: false,
        );
    }
}
