<?php
declare(strict_types=1);

namespace Neos\Api\Domain;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Neos\Domain\Model\User as NeosUser;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * A Neos user
 */
final readonly class User implements ProvidesSchema
{
    public function __construct(
        public UserId $id,
        public string $label,
    ) {
    }

    public static function fromNeosUser(NeosUser $user): self
    {
        return new self(UserId::fromString($user->getId()->value), $user->getLabel());
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
