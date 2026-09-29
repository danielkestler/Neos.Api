<?php
declare(strict_types=1);

namespace Neos\Api\Feature\Users\Dto;

use Neos\Api\Feature\Users\Model\AccountIdentifier;
use Neos\Api\Feature\Users\Model\EmailAddress;
use Neos\Api\Feature\Users\Model\Password;
use Neos\Api\Feature\Users\Model\RoleIdentifiers;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Neos\Domain\Model\User as NeosUser;
use Neos\Party\Domain\Model\PersonName;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * A Neos user to create, with a backend account to log in with
 */
final readonly class UserCreate implements ProvidesSchema
{
    /**
     * @param AccountIdentifier $username the identifier of the backend account, unique among them
     * @param EmailAddress|null $email the primary email address
     * @param RoleIdentifiers|null $roles the roles of the account, Neos.Neos:Editor if left out
     */
    public function __construct(
        public AccountIdentifier $username,
        public Password $password,
        public string $firstName,
        public string $lastName,
        public EmailAddress|null $email = null,
        public RoleIdentifiers|null $roles = null,
    ) {
    }

    /**
     * The user without an account, see UserService::addUser()
     */
    public function toNeosUser(): NeosUser
    {
        $user = new NeosUser();
        $user->setName(new PersonName('', $this->firstName, '', $this->lastName, '', $this->username->value));
        $this->email?->makePrimaryOf($user);
        return $user;
    }

    /**
     * @return list<string>|null
     */
    public function roleIdentifiers(): ?array
    {
        return $this->roles !== null
            ? array_map(static fn ($role) => $role->value, iterator_to_array($this->roles, false))
            : null;
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
