<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\NodeTypes\Schema;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * Names of content repository node types
 *
 * @implements \IteratorAggregate<NodeTypeName>
 */
final readonly class NodeTypeNames implements ProvidesSchema, \IteratorAggregate
{
    /**
     * @var list<NodeTypeName>
     */
    public array $names;

    public function __construct(NodeTypeName ...$names)
    {
        $this->names = array_values($names);
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }

    public function getIterator(): \Traversable
    {
        yield from $this->names;
    }
}
