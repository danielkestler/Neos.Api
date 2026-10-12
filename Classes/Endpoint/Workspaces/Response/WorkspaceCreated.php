<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\Workspaces\Response;

use Neos\Api\Endpoint\Workspaces\Schema\Workspace;
use Neos\OpenApi\Binding\BuiltinType;
use Neos\OpenApi\Binding\TypeReference;
use Neos\OpenApi\Response\ApiResponseWithHeaders;
use Neos\OpenApi\Response\ResponseHeader;
use Neos\OpenApi\Response\ResponseHeaders;
use Neos\OpenApi\Support\HttpStatusCode;
use Neos\OpenApi\Support\MediaTypeRange;

/**
 * A 201 with the new workspace and where to find it
 */
final readonly class WorkspaceCreated implements ApiResponseWithHeaders
{
    public function __construct(
        private Workspace $workspace,
    ) {
    }

    public static function statusCode(): HttpStatusCode
    {
        return HttpStatusCode::fromInteger(201);
    }

    public static function description(): string
    {
        return 'The workspace was created';
    }

    public static function bodyType(): TypeReference
    {
        return TypeReference::of(Workspace::class);
    }

    public static function contentType(): MediaTypeRange
    {
        return MediaTypeRange::fromString('application/json');
    }

    public static function headerTypes(): ResponseHeaders
    {
        return ResponseHeaders::create(
            ResponseHeader::create('Location', TypeReference::builtin(BuiltinType::string), description: 'The URI of the new workspace, relative to the request URI'),
        );
    }

    public function body(): Workspace
    {
        return $this->workspace;
    }

    public function headers(): array
    {
        // resolved against POST …/cr/{contentRepositoryId}/workspaces, this is …/workspaces/{workspaceName}
        return ['Location' => 'workspaces/' . $this->workspace->name->value];
    }
}
