<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\NodeTypes\Schema;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * The groups the Neos UI offers node types in for creation
 *
 * @implements \IteratorAggregate<NodeTypeGroup>
 */
final readonly class NodeTypeGroupList implements ProvidesSchema, \IteratorAggregate
{
    /**
     * @var list<NodeTypeGroup>
     */
    public array $groups;

    public function __construct(NodeTypeGroup ...$groups)
    {
        $this->groups = array_values($groups);
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }

    public function getIterator(): \Traversable
    {
        yield from $this->groups;
    }
}
