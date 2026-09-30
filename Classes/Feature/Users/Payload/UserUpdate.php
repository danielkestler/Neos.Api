<?php
declare(strict_types=1);

namespace Neos\Api\Feature\Users\Payload;

use Neos\Api\Feature\Users\Schema\EmailAddress;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Neos\Domain\Model\User as NeosUser;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * Changes to a Neos user: what is left out or null stays as it is
 */
final readonly class UserUpdate implements ProvidesSchema
{
    /**
     * @param EmailAddress|null $email the new primary email address
     */
    public function __construct(
        public string|null $firstName = null,
        public string|null $lastName = null,
        public EmailAddress|null $email = null,
    ) {
    }

    public function applyTo(NeosUser $user): void
    {
        if ($this->firstName !== null) {
            $user->getName()->setFirstName($this->firstName);
        }
        if ($this->lastName !== null) {
            $user->getName()->setLastName($this->lastName);
        }
        $this->email?->makePrimaryOf($user);
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
