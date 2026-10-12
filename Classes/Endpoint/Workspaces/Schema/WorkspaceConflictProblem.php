<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\Workspaces\Schema;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * An RFC 9457 problem document (as Shared\Response\Conflict's) with the conflicting changes as an extension member
 */
final readonly class WorkspaceConflictProblem implements ProvidesSchema
{
    /**
     * @param string $type a URI identifying the problem type
     * @param string $title rebase (the workspace's changes conflict with its base workspace, rebasing with the force strategy drops the conflicting ones) or partial (the selected changes can't be published or discarded without others they depend on)
     * @param int $status the HTTP status code, 409
     * @param string $detail a human-readable explanation
     */
    public function __construct(
        public string $type,
        public string $title,
        public int $status,
        public string $detail,
        public ConflictingChangeList $conflicts,
    ) {
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
