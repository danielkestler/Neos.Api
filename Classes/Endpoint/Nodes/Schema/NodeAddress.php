<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\Nodes\Schema;

use Neos\ContentRepository\Core\SharedModel;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\StringSchema;
use Neos\Schematic\Schematic;

/**
 * The address of a node in the JSON form of Neos\ContentRepository\Core\SharedModel\Node\NodeAddress
 */
final readonly class NodeAddress implements ProvidesSchema
{
    private function __construct(
        public string $value,
    ) {
    }

    public static function fromString(string $value): self
    {
        return Schematic::instantiate(self::class, $value)->valueOrThrow();
    }

    public static function from(SharedModel\Node\NodeAddress $nodeAddress): self
    {
        return self::fromString($nodeAddress->toJson());
    }

    /**
     * @throws \InvalidArgumentException if it isn't a valid node address
     */
    public function toNodeAddress(): SharedModel\Node\NodeAddress
    {
        return SharedModel\Node\NodeAddress::fromJsonString($this->value);
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= StringSchema::create(
            description: 'A node in a workspace and dimension space point of a content repository, as JSON with the keys contentRepositoryId, workspaceName, dimensionSpacePoint and aggregateId. Neos renders it with Neos.Node.serializedNodeAddress(node)',
            examples: ['{"contentRepositoryId":"default","workspaceName":"live","dimensionSpacePoint":{"language":"en_US"},"aggregateId":"a3474e1d-dd60-4a84-82b1-18d2f21891a3"}'],
            minLength: 2,
            pattern: '^\{.*\}$',
        );
    }
}
