<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\Workspaces\Schema;

use Neos\Api\Endpoint\Nodes\Schema\NodeAggregateId;
use Neos\Api\Endpoint\Workspaces\Domain\ChangeLocator;
use Neos\ContentRepository\Core\Feature\WorkspaceRebase\ConflictingEvent;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * A change of a workspace that can't be applied to its base workspace (any more), e.g. to a node removed there
 */
final readonly class ConflictingChange implements ProvidesSchema
{
    /**
     * @param NodeAggregateId|null $nodeAggregateId the node the change is about, null if it isn't about one
     * @param NodeAggregateId|null $documentAggregateId the document the node is in (or is), to group the conflicts, null if unknown
     * @param NodeAggregateId|null $siteAggregateId the site the node is in, null if unknown
     * @param string|null $reason node_has_been_deleted if the node was removed in the base workspace, else null
     * @param string $message what the content repository says about it
     */
    public function __construct(
        public NodeAggregateId|null $nodeAggregateId,
        public NodeAggregateId|null $documentAggregateId,
        public NodeAggregateId|null $siteAggregateId,
        public string|null $reason,
        public string $message,
    ) {
    }

    /**
     * The document and site are located as for the changes (ChangeLocator): in the workspace, where the node still
     * is, in any dimension space point it covers, without visibility constraints, so a conflict about a hidden or
     * removed node is still grouped
     */
    public static function from(ConflictingEvent $conflictingEvent, ChangeLocator $locator): self
    {
        $nodeAggregateId = $conflictingEvent->getAffectedNodeAggregateId();
        $location = $nodeAggregateId !== null ? $locator->locate($nodeAggregateId, null) : null;
        $exception = $conflictingEvent->getException();
        $shortName = substr(strrchr('\\' . $exception::class, '\\') ?: '', 1);
        return new self(
            $nodeAggregateId !== null ? NodeAggregateId::fromString($nodeAggregateId->value) : null,
            isset($location['document']) ? NodeAggregateId::fromString($location['document']->value) : null,
            isset($location['site']) ? NodeAggregateId::fromString($location['site']->value) : null,
            $shortName === 'NodeAggregateCurrentlyDoesNotExist' ? 'node_has_been_deleted' : null,
            $exception->getMessage(),
        );
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
