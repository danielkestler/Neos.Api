<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\Nodes\Schema;

use Neos\JsonSchema\ArraySchema;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\OpenApi\Spec\SchemaObjectMap;
use Neos\Schematic\Serialization\Serializer;

/**
 * A list of nodes inside a node, its children or variants. Not Nodes: that's the endpoint
 *
 * Node contains itself through this list, which neither schematic nor the hoister can express with types: the items
 * are a $ref to the Node component, and Node types its member iterable, so the hoister doesn't follow it into a
 * cycle. The serializer can read no object under a $ref, so the list hands it the nodes serialized already
 *
 * @implements \IteratorAggregate<array<string, mixed>>
 */
final readonly class NodeList implements ProvidesSchema, \IteratorAggregate
{
    /**
     * @var list<Node>
     */
    public array $nodes;

    public function __construct(Node ...$nodes)
    {
        $this->nodes = array_values($nodes);
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= ArraySchema::create(
            description: 'Only if included, else null: with include=children the direct child nodes in their order, with include=variants the node in its other origin dimension space points (its content variants, not the points that only fall back to it) in the order of the content dimensions',
            items: SchemaObjectMap::reference('Node'),
        );
    }

    /**
     * The nodes serialized, see above
     */
    public function getIterator(): \Traversable
    {
        foreach ($this->nodes as $node) {
            yield Serializer::serialize($node, Node::schema());
        }
    }
}
