<?php
declare(strict_types=1);

namespace Neos\Api\Infrastructure\ContentRepository;

use Neos\ContentRepository\Core\Factory\ContentRepositoryServiceInterface;
use Neos\ContentRepository\Core\Feature\ContentStreamEventStreamName;
use Neos\ContentRepository\Core\Feature\ContentStreamForking\Event\ContentStreamWasForked;
use Neos\ContentRepository\Core\SharedModel\Workspace\ContentStreamId;
use Neos\EventStore\EventStoreInterface;
use Neos\EventStore\Model\EventStream\EventStreamInterface;

/**
 * The events of a content stream, read from the content repository's event store
 *
 * The content repository doesn't expose its event store, a service of it (built with ContentStreamEventsFactory) is
 * the only way to it. Both that and the stream name are @internal in Neos 9, so they stay in this class
 */
final readonly class ContentStreamEvents implements ContentRepositoryServiceInterface
{
    public function __construct(
        private EventStoreInterface $eventStore,
    ) {
    }

    /**
     * The events of the content stream, oldest first (backwards() for newest first)
     */
    public function load(ContentStreamId $contentStreamId): EventStreamInterface
    {
        return $this->eventStore->load(ContentStreamEventStreamName::fromContentStreamId($contentStreamId)->getEventStreamName());
    }

    /**
     * The event the content stream starts with if it was forked from another one (where it branched off), else null
     * (e.g. live's, created empty). Reads the first event only
     */
    public function findFork(ContentStreamId $contentStreamId): ContentStreamWasForked|null
    {
        foreach ($this->load($contentStreamId)->limit(1) as $envelope) {
            // the event store keeps the short class name as the type
            if ($envelope->event->type->value !== 'ContentStreamWasForked') {
                return null;
            }
            $payload = json_decode($envelope->event->data->value, true, flags: JSON_THROW_ON_ERROR);
            return ContentStreamWasForked::fromArray($payload);
        }
        return null;
    }
}
