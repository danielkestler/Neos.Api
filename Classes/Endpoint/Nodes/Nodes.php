<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\Nodes;

use Neos\Api\Endpoint\Nodes\Schema\Node;
use Neos\Api\Endpoint\Nodes\Schema\NodeAddress;
use Neos\Api\Endpoint\Nodes\Schema\NodeList;
use Neos\Api\Infrastructure\ContentRepository\ContentSubgraphs;
use Neos\Api\Infrastructure\ContentRepository\NodeSerializer;
use Neos\Api\Security\ApiAuthContextProvider;
use Neos\Api\Security\ApiScopes;
use Neos\Api\Shared\Params;
use Neos\Api\Shared\Response\BadRequest;
use Neos\Api\Shared\Response\NotFound;
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\Projection\ContentGraph;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindChildNodesFilter;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\OpenApi\Attributes\Operation;
use Neos\OpenApi\Attributes\Parameter;

/**
 * The nodes of the content repositories, read directly, without Fusion
 */
final readonly class Nodes
{
    /**
     * The include paths, as JSON:API's: a path includes the ones it goes through, children.references includes children
     */
    private const array INCLUDE_PATHS = ['references', 'children', 'children.references', 'variants', 'variants.references'];

    public function __construct(
        private ContentRepositoryRegistry $contentRepositoryRegistry,
        private ContentSubgraphs $contentSubgraphs,
        private NodeSerializer $nodeSerializer,
    ) {
    }

    #[Operation(
        path: '/nodes/{nodeAddress}',
        method: 'GET',
        summary: 'Get a node',
        description: 'A node with its properties and what is included: with include=references its references, with children its direct child nodes in their order, with variants the node in its other origin dimension space points (its content variants, not the points that only fall back to it), in the order of the content dimensions. children.references and variants.references include their references as well. Hidden nodes are visible to accounts that may see them in every workspace, live included, as in the Neos backend; isHidden tells them apart. The node address is URL-encoded JSON, a / in it as %2F: Apache rejects that in paths unless AllowEncodedSlashes is on.',
        operationId: 'getNode',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::NODES_READ],
        ],
    )]
    public function get(
        NodeAddress $nodeAddress,
        #[Parameter(in: 'query', description: 'What to include beyond the node\'s own fields, comma-separated: references, children, children.references, variants, variants.references')]
        Params\IncludePaths|null $include = null,
    ): Node|NotFound|BadRequest {
        $includePaths = $include?->paths() ?? [];
        $unknown = array_diff($includePaths, self::INCLUDE_PATHS);
        if ($unknown !== []) {
            return BadRequest::because(sprintf('Can\'t include %s, only: %s', implode(', ', $unknown), implode(', ', self::INCLUDE_PATHS)));
        }
        try {
            $address = $nodeAddress->toNodeAddress();
        } catch (\InvalidArgumentException | \TypeError $exception) {
            return BadRequest::because(sprintf('The node address is invalid: %s', $exception->getMessage()));
        }
        $subgraph = $this->contentSubgraphs->find($address->contentRepositoryId, $address->workspaceName, $address->dimensionSpacePoint, excludeDisabled: false);
        $node = $subgraph?->findNodeById($address->aggregateId);
        if ($subgraph === null || $node === null) {
            return NotFound::because(sprintf('There is no node %s', $address->toJson()));
        }
        $includes = static fn (string $path) => array_filter($includePaths, static fn (string $included) => $included === $path || str_starts_with($included, $path . '.')) !== [];
        return Node::from(
            $node,
            $this->nodeSerializer,
            $includes('references'),
            $includes('children') ? $this->children($node, $subgraph, $includes('children.references')) : null,
            $includes('variants') ? $this->variants($node, $includes('variants.references')) : null,
        );
    }

    private function children(ContentGraph\Node $node, ContentSubgraphInterface $subgraph, bool $includeReferences): NodeList
    {
        return new NodeList(...array_map(
            fn (ContentGraph\Node $child) => Node::from($child, $this->nodeSerializer, $includeReferences),
            iterator_to_array($subgraph->findChildNodes($node->aggregateId, FindChildNodesFilter::create()), false),
        ));
    }

    /**
     * The node in the other dimension space points its aggregate occupies, each read in that point with the account's
     * visibility, so a variant the account may not see is left out
     */
    private function variants(ContentGraph\Node $node, bool $includeReferences): NodeList
    {
        $contentRepository = $this->contentRepositoryRegistry->get($node->contentRepositoryId);
        $nodeAggregate = $contentRepository->getContentGraph($node->workspaceName)->findNodeAggregateById($node->aggregateId);
        $variants = [];
        // in the order of the content dimensions, the occupied points are in no particular order
        foreach ($contentRepository->getVariationGraph()->getDimensionSpacePoints() as $dimensionSpacePoint) {
            $origin = OriginDimensionSpacePoint::fromDimensionSpacePoint($dimensionSpacePoint);
            if ($nodeAggregate?->occupiesDimensionSpacePoint($origin) !== true || $origin->equals($node->originDimensionSpacePoint)) {
                continue;
            }
            $variant = $this->contentSubgraphs
                ->find($node->contentRepositoryId, $node->workspaceName, $dimensionSpacePoint, excludeDisabled: false)
                ?->findNodeById($node->aggregateId);
            if ($variant !== null) {
                $variants[] = Node::from($variant, $this->nodeSerializer, $includeReferences);
            }
        }
        return new NodeList(...$variants);
    }
}
