<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\Nodes\RequestBody;

use Neos\Api\Endpoint\Nodes\Schema\SubtreeTag;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * A tag to set on a node
 *
 * There is no applyTo(): a node is tagged by a command, which the endpoint builds
 */
final readonly class NodeTag implements ProvidesSchema
{
    /**
     * @param SubtreeTag $tag e.g. disabled to hide the node, anything but removed (deleteNode removes nodes)
     */
    public function __construct(
        public SubtreeTag $tag,
    ) {
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
