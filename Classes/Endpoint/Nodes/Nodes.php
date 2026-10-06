<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\Nodes;

use Neos\Api\Endpoint\Nodes\Params\NodeFilter;
use Neos\Api\Endpoint\Nodes\Schema\Node;
use Neos\Api\Endpoint\Nodes\Schema\NodeAddress;
use Neos\Api\Endpoint\Nodes\Schema\NodeList;
use Neos\Api\Endpoint\Nodes\Schema\PaginatedNodeListing;
use Neos\Api\Infrastructure\ContentRepository\ContentSubgraphs;
use Neos\Api\Infrastructure\ContentRepository\NodeSerializer;
use Neos\Api\Security\ApiAuthContextProvider;
use Neos\Api\Security\ApiScopes;
use Neos\Api\Shared\Params;
use Neos\Api\Shared\Response\BadRequest;
use Neos\Api\Shared\Response\NotFound;
use Neos\Api\Shared\Schema\ListingLinks;
use Neos\Api\Shared\Schema\ListingMeta;
use Neos\ContentRepository\Core\Projection\ContentGraph;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindChildNodesFilter;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\OpenApi\Attributes\Operation;
use Neos\OpenApi\Attributes\Parameter;
use Psr\Http\Message\ServerRequestInterface;

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
        path: '/nodes',
        method: 'GET',
        summary: 'List nodes',
        description: 'A page of the nodes found from one node, in its workspace and dimension space point: with filter[parent] its direct child nodes (in their order unless sorted), with filter[ancestor] all nodes below it, with filter[referencing] the nodes that reference it, a node once per reference. The other filter members narrow them down. sort takes properties.<name> and timestamps.created, timestamps.lastModified, timestamps.originalCreated, timestamps.originalLastModified. page[offset] and page[limit] (25 by default, 100 at most) choose the page, meta.total and links tell about the others. include works as in getNode, for each node. Hidden nodes are visible as in getNode.',
        operationId: 'listNodes',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::NODES_READ],
        ],
    )]
    public function list(
        ServerRequestInterface $request,
        #[Parameter(in: 'query', description: 'Which nodes: exactly one of filter[parent], filter[ancestor] and filter[referencing] (a node address each), narrowed down by filter[nodeType], filter[search], filter[property] and, with filter[referencing], filter[referenceName]')]
        NodeFilter|null $filter = null,
        #[Parameter(in: 'query', description: 'The fields to sort by, comma-separated, each ascending unless prefixed with -: properties.<name>, timestamps.created, timestamps.lastModified, timestamps.originalCreated, timestamps.originalLastModified')]
        Params\Sort|null $sort = null,
        #[Parameter(in: 'query', description: 'Which page: page[offset] and page[limit]')]
        Params\Page|null $page = null,
        #[Parameter(in: 'query', description: 'What to include beyond each node\'s own fields, comma-separated: references, children, children.references, variants, variants.references')]
        Params\IncludePaths|null $include = null,
    ): PaginatedNodeListing|NotFound|BadRequest {
        $page ??= new Params\Page();
        $include ??= Params\IncludePaths::none();
        $entryPoints = $filter?->entryPoints() ?? [];
        if ($filter === null || count($entryPoints) !== 1) {
            return BadRequest::because('Exactly one of filter[parent], filter[ancestor] and filter[referencing] is required');
        }
        if ($filter->referenceName !== null && $filter->referencing === null) {
            return BadRequest::because('filter[referenceName] needs filter[referencing]');
        }
        $unsupported = $include->unsupported(self::INCLUDE_PATHS);
        if ($unsupported !== null) {
            return $unsupported;
        }
        $query = NodeQuery::create($filter, $sort, $page);
        if ($query instanceof BadRequest) {
            return $query;
        }
        $entryPoint = $this->findNode($entryPoints[0]);
        if (!$entryPoint instanceof ContentGraph\Node) {
            return $entryPoint;
        }
        $subgraph = $this->contentRepositoryRegistry->subgraphForNode($entryPoint);
        $nodeTypeManager = $this->contentRepositoryRegistry->get($entryPoint->contentRepositoryId)->getNodeTypeManager();
        $found = $query->find($entryPoint, $subgraph, $nodeTypeManager);
        if ($found instanceof BadRequest) {
            return $found;
        }
        [$nodes, $total] = $found;
        return new PaginatedNodeListing(
            new NodeList(...array_map(fn (ContentGraph\Node $node) => $this->node($node, $include), $nodes)),
            new ListingMeta($total),
            ListingLinks::for($request, $page, $total),
        );
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
        $include ??= Params\IncludePaths::none();
        $unsupported = $include->unsupported(self::INCLUDE_PATHS);
        if ($unsupported !== null) {
            return $unsupported;
        }
        $node = $this->findNode($nodeAddress);
        return $node instanceof ContentGraph\Node ? $this->node($node, $include) : $node;
    }

    /**
     * The node at the address with the visibility of the Neos backend, a 400 if the address is invalid, a 404 if there
     * is no such node the account may read
     */
    private function findNode(NodeAddress $nodeAddress): ContentGraph\Node|BadRequest|NotFound
    {
        try {
            $address = $nodeAddress->toNodeAddress();
        } catch (\InvalidArgumentException | \TypeError $exception) {
            return BadRequest::because(sprintf('The node address is invalid: %s', $exception->getMessage()));
        }
        return $this->contentSubgraphs->findNode($address, excludeDisabled: false)
            ?? NotFound::because(sprintf('There is no node %s', $address->toJson()));
    }

    private function node(ContentGraph\Node $node, Params\IncludePaths $include): Node
    {
        return Node::from(
            $node,
            $this->nodeSerializer,
            $include->includes('references'),
            $include->includes('children') ? $this->nodeList($this->children($node), $include->includes('children.references')) : null,
            $include->includes('variants') ? $this->nodeList($this->contentSubgraphs->findVariants($node, excludeDisabled: false), $include->includes('variants.references')) : null,
        );
    }

    /**
     * @return list<ContentGraph\Node>
     */
    private function children(ContentGraph\Node $node): array
    {
        return iterator_to_array($this->contentRepositoryRegistry->subgraphForNode($node)->findChildNodes($node->aggregateId, FindChildNodesFilter::create()), false);
    }

    /**
     * @param list<ContentGraph\Node> $nodes
     */
    private function nodeList(array $nodes, bool $includeReferences): NodeList
    {
        return new NodeList(...array_map(fn (ContentGraph\Node $node) => Node::from($node, $this->nodeSerializer, $includeReferences), $nodes));
    }
}
