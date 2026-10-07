<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\Nodes\Params;

use Neos\Api\Endpoint\Nodes\Schema\NodeAddress;
use Neos\JsonSchema\ObjectSchema;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\StringSchema;
use Neos\JsonSchema\Support\ObjectProperties;

/**
 * Which nodes to list, as JSON:API's filter family: ?filter[parent]=…&filter[nodeType]=Neos.Neos:Document
 *
 * At most one of parent, ancestor and referencing says where to look, each is a query of its own in the content
 * repository, so they can't be combined. Its node address says the workspace and dimension space point as well. Without
 * any, it's the nodes below the site node of the default site
 */
final readonly class NodeFilter implements ProvidesSchema
{
    public function __construct(
        public NodeAddress|null $parent = null,
        public NodeAddress|null $ancestor = null,
        public NodeAddress|null $referencing = null,
        public string|null $referenceName = null,
        public string|null $nodeType = null,
        public string|null $search = null,
        public string|null $property = null,
    ) {
    }

    /**
     * @return list<NodeAddress>
     */
    public function entryPoints(): array
    {
        return array_values(array_filter([$this->parent, $this->ancestor, $this->referencing]));
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= ObjectSchema::create(
            description: 'Which nodes to list. At most one of filter[parent], filter[ancestor] and filter[referencing], its node address says the workspace and dimension space point as well, without any all nodes below the site node of the default site. The other members narrow them down',
            properties: ObjectProperties::create(
                parent: NodeAddress::schema(),
                ancestor: NodeAddress::schema(),
                referencing: NodeAddress::schema(),
                referenceName: StringSchema::create(description: 'With filter[referencing], only the references of this name', examples: ['relatedPages'], minLength: 1),
                nodeType: StringSchema::create(description: 'Only nodes of these node types or ones inheriting from them, comma-separated, a ! in front excludes a type and the ones inheriting from it', examples: ['Neos.Neos:Document,!Neos.Neos:Shortcut'], minLength: 1),
                search: StringSchema::create(description: 'Only nodes with a property containing this text', examples: ['neos'], minLength: 1),
                property: StringSchema::create(description: 'Only nodes whose properties match, in the content repository\'s syntax: comparisons like title = \'Home\', title != \'Home\', title *= \'contains\', title ^= \'starts\', title $= \'ends\', count > 3 (also >=, <, <=, values are strings in quotes, numbers or true/false), combined with AND, OR, NOT and parentheses', examples: ['title *= \'Neos\' AND NOT (hideInMenu = true)'], minLength: 1),
            ),
            additionalProperties: false,
        );
    }
}
