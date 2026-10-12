<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\Workspaces\Response;

use Neos\Api\Endpoint\Workspaces\Schema\ConflictingChangeList;
use Neos\Api\Endpoint\Workspaces\Schema\WorkspaceConflictProblem;
use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\Feature\WorkspaceRebase\Exception\PartialWorkspaceRebaseFailed;
use Neos\ContentRepository\Core\Feature\WorkspaceRebase\Exception\WorkspaceRebaseFailed;
use Neos\ContentRepository\Core\SharedModel;
use Neos\OpenApi\Binding\TypeReference;
use Neos\OpenApi\Problem\ProblemDocument;
use Neos\OpenApi\Response\ApiResponse;
use Neos\OpenApi\Support\HttpStatusCode;
use Neos\OpenApi\Support\MediaTypeRange;

/**
 * A 409 for a publish, discard or rebase whose changes conflict, with the conflicting changes
 */
final readonly class WorkspaceConflict implements ApiResponse
{
    private function __construct(
        private WorkspaceConflictProblem $problem,
    ) {
    }

    /**
     * The content repository's exception of a publish, discard or rebase: the workspace's changes conflict with its
     * base workspace (rebase) or the selected ones can't be separated from the others (partial)
     */
    public static function of(WorkspaceRebaseFailed|PartialWorkspaceRebaseFailed $exception, ContentRepository $contentRepository, SharedModel\Workspace\WorkspaceName $workspaceName, string $detail): self
    {
        return new self(new WorkspaceConflictProblem(
            sprintf('https://www.rfc-editor.org/rfc/rfc9110#status.%d', self::statusCode()->value),
            $exception instanceof PartialWorkspaceRebaseFailed ? 'partial' : 'rebase',
            self::statusCode()->value,
            $detail,
            ConflictingChangeList::from($exception->conflictingEvents, $contentRepository, $workspaceName),
        ));
    }

    public static function statusCode(): HttpStatusCode
    {
        return HttpStatusCode::fromInteger(409);
    }

    public static function description(): string
    {
        return 'The request conflicts with the current state, or the workspace\'s changes conflict with its base workspace';
    }

    public static function bodyType(): TypeReference
    {
        return TypeReference::of(WorkspaceConflictProblem::class);
    }

    public static function contentType(): MediaTypeRange
    {
        return ProblemDocument::contentType();
    }

    public function body(): WorkspaceConflictProblem
    {
        return $this->problem;
    }
}
