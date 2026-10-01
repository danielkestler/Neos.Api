<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\Nodes;

use Neos\Api\Endpoint\Nodes\Schema\Node;
use Neos\Api\Endpoint\Nodes\Schema\NodeAddress;
use Neos\Api\Endpoint\Nodes\Schema\NodeInclude;
use Neos\Api\Infrastructure\ContentRepository\ContentSubgraphs;
use Neos\Api\Infrastructure\ContentRepository\NodeSerializer;
use Neos\Api\Security\ApiAuthContextProvider;
use Neos\Api\Security\ApiScopes;
use Neos\Api\Shared\Response\BadRequest;
use Neos\Api\Shared\Response\NotFound;
use Neos\OpenApi\Attributes\Operation;
use Neos\OpenApi\Attributes\Parameter;

/**
 * The nodes of the content repositories, read directly, without Fusion
 */
final readonly class Nodes
{
    private const string INCLUDE_REFERENCES = 'references';

    public function __construct(
        private ContentSubgraphs $contentSubgraphs,
        private NodeSerializer $nodeSerializer,
    ) {
    }

    #[Operation(
        path: '/nodes/{nodeAddress}',
        method: 'GET',
        summary: 'Get a node',
        description: 'A node with its properties, and with include=references its references. Hidden nodes are visible to accounts that may see them in every workspace, live included, as in the Neos backend; isHidden tells them apart. The node address is URL-encoded JSON, a / in it as %2F: Apache rejects that in paths unless AllowEncodedSlashes is on.',
        operationId: 'getNode',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::NODES_READ],
        ],
    )]
    public function get(
        NodeAddress $nodeAddress,
        #[Parameter(in: 'query', description: 'What to include beyond the node\'s own fields, like references')]
        NodeInclude|null $include = null,
    ): Node|NotFound|BadRequest {
        $includePaths = $include?->paths() ?? [];
        $unknown = array_diff($includePaths, [self::INCLUDE_REFERENCES]);
        if ($unknown !== []) {
            return BadRequest::because(sprintf('Can\'t include %s, only: %s', implode(', ', $unknown), self::INCLUDE_REFERENCES));
        }
        try {
            $address = $nodeAddress->toNodeAddress();
        } catch (\InvalidArgumentException | \TypeError $exception) {
            return BadRequest::because(sprintf('The node address is invalid: %s', $exception->getMessage()));
        }
        $node = $this->contentSubgraphs
            ->find($address->contentRepositoryId, $address->workspaceName, $address->dimensionSpacePoint, excludeDisabled: false)
            ?->findNodeById($address->aggregateId);
        if ($node === null) {
            return NotFound::because(sprintf('There is no node %s', $address->toJson()));
        }
        return Node::from($node, $this->nodeSerializer, in_array(self::INCLUDE_REFERENCES, $includePaths, true));
    }
}
