<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\Nodes\Response;

use Neos\Api\Endpoint\Nodes\Schema\Node;
use Neos\OpenApi\Binding\BuiltinType;
use Neos\OpenApi\Binding\TypeReference;
use Neos\OpenApi\Response\ApiResponseWithHeaders;
use Neos\OpenApi\Response\ResponseHeader;
use Neos\OpenApi\Response\ResponseHeaders;
use Neos\OpenApi\Support\HttpStatusCode;
use Neos\OpenApi\Support\MediaTypeRange;

/**
 * A 201 with the new node and where to find it
 */
final readonly class NodeCreated implements ApiResponseWithHeaders
{
    public function __construct(
        private Node $node,
    ) {
    }

    public static function statusCode(): HttpStatusCode
    {
        return HttpStatusCode::fromInteger(201);
    }

    public static function description(): string
    {
        return 'The node was created';
    }

    public static function bodyType(): TypeReference
    {
        return TypeReference::of(Node::class);
    }

    public static function contentType(): MediaTypeRange
    {
        return MediaTypeRange::fromString('application/json');
    }

    public static function headerTypes(): ResponseHeaders
    {
        return ResponseHeaders::create(
            ResponseHeader::create('Location', TypeReference::builtin(BuiltinType::string), description: 'The URI of the new node in its workspace and dimension space point, relative to the request URI'),
        );
    }

    public function body(): Node
    {
        return $this->node;
    }

    public function headers(): array
    {
        // resolved against POST …/cr/{contentRepositoryId}/nodes, this is …/nodes/{nodeAggregateId}, whatever the API's URI prefix is
        return ['Location' => sprintf(
            'nodes/%s?%s',
            rawurlencode($this->node->nodeAggregateId->value),
            http_build_query(['workspaceName' => $this->node->workspaceName->value, 'dimensionSpacePoint' => $this->node->dimensionSpacePoint->value], '', '&', PHP_QUERY_RFC3986),
        )];
    }
}
