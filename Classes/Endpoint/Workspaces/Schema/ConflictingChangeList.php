<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\Workspaces\Schema;

use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\Feature\WorkspaceRebase\ConflictingEvents;
use Neos\ContentRepository\Core\SharedModel;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * The conflicting changes of a publish, discard or rebase, one per node
 *
 * @implements \IteratorAggregate<ConflictingChange>
 */
final readonly class ConflictingChangeList implements ProvidesSchema, \IteratorAggregate
{
    /**
     * @var list<ConflictingChange>
     */
    public array $conflictingChanges;

    public function __construct(ConflictingChange ...$conflictingChanges)
    {
        $this->conflictingChanges = array_values($conflictingChanges);
    }

    /**
     * Several events about one node are one conflict for the caller
     */
    public static function from(ConflictingEvents $conflictingEvents, ContentRepository $contentRepository, SharedModel\Workspace\WorkspaceName $workspaceName): self
    {
        $changes = [];
        foreach ($conflictingEvents as $conflictingEvent) {
            $key = $conflictingEvent->getAffectedNodeAggregateId()?->value ?? '#' . count($changes);
            $changes[$key] ??= ConflictingChange::from($conflictingEvent, $contentRepository, $workspaceName);
        }
        return new self(...array_values($changes));
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }

    public function getIterator(): \Traversable
    {
        yield from $this->conflictingChanges;
    }
}
