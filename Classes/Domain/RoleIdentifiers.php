<?php
declare(strict_types=1);

namespace Neos\Api\Domain;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * The roles assigned to an account
 *
 * @implements \IteratorAggregate<RoleIdentifier>
 */
final readonly class RoleIdentifiers implements ProvidesSchema, \IteratorAggregate
{
    /**
     * @var list<RoleIdentifier>
     */
    public array $roles;

    public function __construct(RoleIdentifier ...$roles)
    {
        $this->roles = array_values($roles);
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }

    public function getIterator(): \Traversable
    {
        yield from $this->roles;
    }
}
