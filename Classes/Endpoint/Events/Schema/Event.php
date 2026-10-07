<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\Events\Schema;

use Neos\Api\Infrastructure\Json\JsonValues;
use Neos\EventStore\Model\EventEnvelope;
use Neos\JsonSchema\IntegerSchema;
use Neos\JsonSchema\Nullable;
use Neos\JsonSchema\ObjectSchema;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\StringSchema;
use Neos\JsonSchema\Support\ObjectProperties;

/**
 * An event of the content repository as the event store keeps it
 *
 * The schema is written out: payload and metadata are maps, which schematic can't discover
 */
final readonly class Event implements ProvidesSchema
{
    /**
     * @param array<string, mixed>|\stdClass $payload
     * @param array<string, mixed>|\stdClass|null $metadata
     */
    public function __construct(
        public SequenceNumber $sequenceNumber,
        public int $version,
        public string $id,
        public string $type,
        public string $recordedAt,
        public array|\stdClass $payload,
        public array|\stdClass|null $metadata,
        public string|null $causationId,
        public string|null $correlationId,
    ) {
    }

    public static function from(EventEnvelope $envelope): self
    {
        $event = $envelope->event;
        return new self(
            SequenceNumber::fromInteger($envelope->sequenceNumber->value),
            $envelope->version->value,
            $event->id->value,
            $event->type->value,
            $envelope->recordedAt->format(\DateTimeInterface::ATOM),
            JsonValues::decodeObject($event->data->value),
            match (true) {
                $event->metadata === null => null,
                // encodes as [] otherwise
                $event->metadata->value === [] => new \stdClass(),
                default => JsonValues::decodeObject($event->metadata->toJson()),
            },
            $event->causationId?->value,
            $event->correlationId?->value,
        );
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= ObjectSchema::create(
            description: 'An event of the content repository as the event store keeps it',
            properties: ObjectProperties::create(
                sequenceNumber: SequenceNumber::schema(),
                version: IntegerSchema::create(description: 'The position of the event in its content stream, from 0', minimum: 0),
                id: StringSchema::create(description: 'The id of the event', examples: ['8b2a3c1e-5d4f-4e6a-9b7c-0d1e2f3a4b5c']),
                type: StringSchema::create(description: 'What happened, the short name of the event class', examples: ['NodePropertiesWereSet', 'NodeAggregateWithNodeWasCreated']),
                recordedAt: StringSchema::create(description: 'When the event was stored, as ISO 8601', examples: ['2026-10-07T09:30:00+00:00']),
                payload: ObjectSchema::create(
                    description: 'The event\'s data as the content repository serializes it, its shape depends on the type',
                    additionalProperties: true,
                ),
                metadata: Nullable::wrap(ObjectSchema::create(
                    description: 'What Neos records about the event besides its data, e.g. initiatingUserId and initiatingTimestamp of the command that caused it, null if none',
                    additionalProperties: true,
                )),
                causationId: Nullable::wrap(StringSchema::create(description: 'The id of what caused the event, null if not recorded')),
                correlationId: Nullable::wrap(StringSchema::create(description: 'The id the events of one command or one publish share, null if not recorded')),
            ),
            additionalProperties: false,
            required: ['sequenceNumber', 'version', 'id', 'type', 'recordedAt', 'payload', 'metadata', 'causationId', 'correlationId'],
        );
    }
}
