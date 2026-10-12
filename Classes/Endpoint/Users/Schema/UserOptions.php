<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\Users\Schema;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * What a user can be created or changed with
 */
final readonly class UserOptions implements ProvidesSchema
{
    /**
     * @param AccountRoleList $roles the non-abstract roles, ordered by label
     */
    public function __construct(
        public AccountRoleList $roles,
    ) {
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
