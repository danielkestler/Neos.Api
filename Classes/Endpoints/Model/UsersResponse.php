<?php
declare(strict_types=1);

namespace Neos\Api\Endpoints\Model;

use Neos\Api\Domain\Users;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * The Neos users
 */
final readonly class UsersResponse implements ProvidesSchema
{
    public function __construct(
        public Users $users,
    ) {
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
