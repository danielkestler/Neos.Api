<?php
declare(strict_types=1);

namespace Neos\Api\Domain;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\StringSchema;
use Neos\JsonSchema\Support\StringFormat;
use Neos\Schematic\Schematic;

final readonly class EmailAddress implements ProvidesSchema
{
    /**
     * The type of a Neos.Party ElectronicAddress holding an email address, it has no constant for it (see its
     * ElectronicAddressValidator)
     */
    public const string ELECTRONIC_ADDRESS_TYPE = 'Email';

    private function __construct(
        public string $value,
    ) {
    }

    public static function fromString(string $value): self
    {
        return Schematic::instantiate(self::class, $value)->valueOrThrow();
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= StringSchema::create(
            description: 'An email address',
            examples: ['editor@example.com'],
            format: StringFormat::email,
        );
    }
}
