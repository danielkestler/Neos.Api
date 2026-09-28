<?php
declare(strict_types=1);

namespace Neos\Api\Endpoints\Model;

use Neos\Api\Domain\Account;
use Neos\Api\Domain\User;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * The account an access token acts as, the user it belongs to, and what the token grants
 */
final readonly class MeResponse implements ProvidesSchema
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
