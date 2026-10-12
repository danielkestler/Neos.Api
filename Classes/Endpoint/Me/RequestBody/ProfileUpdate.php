<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\Me\RequestBody;

use Neos\Api\Endpoint\Me\Schema\Profile;
use Neos\Api\Endpoint\Users\Schema\EmailAddress;
use Neos\JsonSchema\Nullable;
use Neos\JsonSchema\ObjectSchema;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\StringSchema;
use Neos\JsonSchema\Support\ObjectProperties;
use Neos\Neos\Domain\Model;

/**
 * Changes to the profile: what is left out or null stays as it is
 *
 * The schema is written out, preferences is a map, which schematic can't discover from an array
 */
final readonly class ProfileUpdate implements ProvidesSchema
{
    /**
     * @param array<string, mixed>|null $preferences
     */
    public function __construct(
        public string|null $firstName = null,
        public string|null $lastName = null,
        public EmailAddress|null $email = null,
        public string|null $interfaceLanguage = null,
        public array|null $preferences = null,
    ) {
    }

    public function applyTo(Model\User $user): void
    {
        if ($this->firstName !== null) {
            $user->getName()->setFirstName($this->firstName);
        }
        if ($this->lastName !== null) {
            $user->getName()->setLastName($this->lastName);
        }
        $this->email?->makePrimaryOf($user);
        if ($this->interfaceLanguage !== null) {
            $user->getPreferences()->setInterfaceLanguage($this->interfaceLanguage);
        }
        if ($this->preferences !== null) {
            $preferences = $user->getPreferences()->getPreferences();
            foreach ($this->preferences as $name => $value) {
                if ($value === null) {
                    unset($preferences[$name]);
                } else {
                    $preferences[$name] = $value;
                }
            }
            $user->getPreferences()->setPreferences($preferences);
        }
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= ObjectSchema::create(
            description: 'Changes to the profile: what is left out or null stays as it is',
            properties: ObjectProperties::create(
                firstName: Nullable::wrap(StringSchema::create(minLength: 1)),
                lastName: Nullable::wrap(StringSchema::create(minLength: 1)),
                email: Nullable::wrap(EmailAddress::schema()),
                interfaceLanguage: Nullable::wrap(StringSchema::create(
                    description: 'One of Neos.Neos.userInterface.availableLanguages',
                    examples: ['de'],
                )),
                preferences: Nullable::wrap(ObjectSchema::create(
                    description: sprintf('The preferences to set by name, null removes one, the ones left out stay as they are. %s is no preference, set interfaceLanguage instead', Profile::INTERFACE_LANGUAGE_PREFERENCE),
                    examples: [['studio.uiMode' => 'dark', 'studio.sidebar' => null]],
                    additionalProperties: true,
                )),
            ),
            additionalProperties: false,
        );
    }
}
