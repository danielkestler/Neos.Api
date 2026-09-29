<?php
declare(strict_types=1);

namespace Neos\Api\Feature\Users\Model;

use Neos\Flow\Security\Account as FlowAccount;
use Neos\Flow\Security\Policy\Role;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * A Neos account and the roles assigned to it
 */
final readonly class Account implements ProvidesSchema
{
    public function __construct(
        public AccountIdentifier $identifier,
        public RoleIdentifiers $roles,
    ) {
    }

    public static function fromFlowAccount(FlowAccount $account): self
    {
        return new self(
            AccountIdentifier::fromString($account->getAccountIdentifier()),
            new RoleIdentifiers(...array_map(
                static fn (Role $role) => RoleIdentifier::fromString($role->getIdentifier()),
                array_values($account->getRoles()),
            )),
        );
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
