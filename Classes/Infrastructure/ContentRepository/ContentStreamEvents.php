<?php
declare(strict_types=1);

namespace Neos\Api\Infrastructure\ContentRepository;

use Neos\ContentRepository\Core\Factory\ContentRepositoryServiceInterface;
use Neos\ContentRepository\Core\Feature\ContentStreamEventStreamName;
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
     * The events of the content stream, oldest first
     */
    public function load(ContentStreamId $contentStreamId): EventStreamInterface
    {
        return $this->eventStore->load(ContentStreamEventStreamName::fromContentStreamId($contentStreamId)->getEventStreamName());
    }
}
