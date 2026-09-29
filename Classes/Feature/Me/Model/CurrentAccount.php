<?php
declare(strict_types=1);

namespace Neos\Api\Feature\Me\Model;

use Neos\Api\Feature\Users\Model\Account;
use Neos\Api\Feature\Users\Model\User;
use Neos\Api\SharedModel\AccessTokenGrant;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * The account an access token acts as, the user it belongs to, and what the token grants
 */
final readonly class CurrentAccount implements ProvidesSchema
{
    /**
     * @param User|null $user null if the account belongs to no Neos user
     */
    public function __construct(
        public Account $account,
        public User|null $user,
        public AccessTokenGrant $token,
    ) {
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
