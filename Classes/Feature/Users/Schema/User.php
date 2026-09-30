<?php
declare(strict_types=1);

namespace Neos\Api\Feature\Users\Schema;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Neos\Domain\Model;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * A Neos user
 */
final readonly class User implements ProvidesSchema
{
    /**
     * @param EmailAddress|null $email the primary email address, null if the user has none
     * @param bool $active whether one of the user's accounts may log in
     */
    public function __construct(
        public UserId $id,
        public string $label,
        public string $firstName,
        public string $lastName,
        public EmailAddress|null $email,
        public bool $active,
        public Accounts $accounts,
    ) {
    }

    public static function from(Model\User $user): self
    {
        return new self(
            UserId::fromString($user->getId()->value),
            $user->getLabel(),
            $user->getName()->getFirstName(),
            $user->getName()->getLastName(),
            EmailAddress::primaryOf($user),
            $user->isActive(),
            new Accounts(...array_map(
                Account::from(...),
                array_values($user->getAccounts()->toArray()),
            )),
        );
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
