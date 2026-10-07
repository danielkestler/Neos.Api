<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\Nodes;

use Neos\Api\Endpoint\ContentRepositories\Schema\ContentRepositoryId;
use Neos\Api\Endpoint\Nodes\Params\NodeFilter;
use Neos\Api\Endpoint\Nodes\Schema\DimensionSpacePoint;
use Neos\Api\Endpoint\Nodes\Schema\Node;
use Neos\Api\Endpoint\Nodes\Schema\NodeAggregateId;
use Neos\Api\Endpoint\Nodes\Schema\NodeList;
use Neos\Api\Endpoint\Nodes\Schema\PaginatedNodeListing;
use Neos\Api\Endpoint\Workspaces\Schema\WorkspaceName;
use Neos\Api\Infrastructure\ContentRepository\ContentSubgraphs;
use Neos\Api\Infrastructure\ContentRepository\NodeSerializer;
use Neos\Api\Infrastructure\ContentRepository\SiteFinder;
use Neos\Api\Security\ApiAuthContextProvider;
use Neos\Api\Security\ApiScopes;
use Neos\Api\Shared\Params;
use Neos\Api\Shared\Response\BadRequest;
use Neos\Api\Shared\Response\NotFound;
use Neos\Api\Shared\Schema\ListingLinks;
use Neos\Api\Shared\Schema\ListingMeta;
use Neos\ContentRepository\Core\Projection\ContentGraph;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindChildNodesFilter;
use Neos\ContentRepository\Core\SharedModel;
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
        private SubgraphResolver $subgraphResolver,
        private SiteFinder $siteFinder,
        private NodeSerializer $nodeSerializer,
    ) {
    }

    #[Operation(
        path: '/cr/{contentRepositoryId}/nodes',
        method: 'GET',
        summary: 'List nodes',
        description: 'A page of the nodes found from one node, in the workspace and dimension space point: with filter[parent] its direct child nodes (in their order unless sorted), with filter[ancestor] all nodes below it, with filter[referencing] the nodes that reference it, a node once per reference. Without any of them, all nodes below the site node of the content repository\'s default site (Neos.Neos.defaultSiteNodeName if it is in the content repository, else its first online site by name). The other filter members narrow them down. sort takes properties.<name> and timestamps.created, timestamps.lastModified, timestamps.originalCreated, timestamps.originalLastModified. page[offset] and page[limit] (25 by default, 100 at most) choose the page, meta.total and links tell about the others. include works as in getNode, for each node. Hidden nodes are visible as in getNode.',
        operationId: 'listNodes',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::NODES_READ],
        ],
    )]
    public function list(
        ContentRepositoryId $contentRepositoryId,
        ServerRequestInterface $request,
        #[Parameter(in: 'query', description: 'The workspace to read the nodes in, live if omitted')]
        WorkspaceName|null $workspaceName = null,
        #[Parameter(in: 'query', description: 'The dimension space point to read the nodes in, as JSON. If omitted, the default one of the content repository\'s default site (Neos.Neos.defaultSiteNodeName if it is in the content repository, else its first online site by name), the only one of a content repository without a site')]
        DimensionSpacePoint|null $dimensionSpacePoint = null,
        #[Parameter(in: 'query', description: 'Which nodes: at most one of filter[parent], filter[ancestor] and filter[referencing] (a node aggregate id each), all nodes below the site node of the default site without any, narrowed down by filter[nodeType], filter[search], filter[property] and, with filter[referencing], filter[referenceName]')]
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
        $filter ??= new NodeFilter();
        $entryPoints = $filter->entryPoints();
        if (count($entryPoints) > 1) {
            return BadRequest::because('At most one of filter[parent], filter[ancestor] and filter[referencing] is allowed');
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
        $subgraph = $this->subgraph($contentRepositoryId, $workspaceName, $dimensionSpacePoint);
        if (!$subgraph instanceof ContentSubgraphInterface) {
            return $subgraph;
        }
        $entryPoint = $entryPoints !== [] ? $this->findNode($subgraph, $entryPoints[0]) : $this->defaultSiteNode($subgraph);
        if (!$entryPoint instanceof ContentGraph\Node) {
            return $entryPoint;
        }
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
        path: '/cr/{contentRepositoryId}/nodes/{aggregateId}',
        method: 'GET',
        summary: 'Get a node',
        description: 'A node in the workspace and dimension space point with its properties and what is included: with include=references its references, with children its direct child nodes in their order, with variants the node in its other origin dimension space points (its content variants, not the points that only fall back to it), in the order of the content dimensions. children.references and variants.references include their references as well. Hidden nodes are visible to accounts that may see them in every workspace, live included, as in the Neos backend; isHidden tells them apart.',
        operationId: 'getNode',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::NODES_READ],
        ],
    )]
    public function get(
        ContentRepositoryId $contentRepositoryId,
        NodeAggregateId $aggregateId,
        #[Parameter(in: 'query', description: 'The workspace to read the node in, live if omitted')]
        WorkspaceName|null $workspaceName = null,
        #[Parameter(in: 'query', description: 'The dimension space point to read the node in, as JSON. If omitted, the default one of the content repository\'s default site (Neos.Neos.defaultSiteNodeName if it is in the content repository, else its first online site by name), the only one of a content repository without a site')]
        DimensionSpacePoint|null $dimensionSpacePoint = null,
        #[Parameter(in: 'query', description: 'What to include beyond the node\'s own fields, comma-separated: references, children, children.references, variants, variants.references')]
        Params\IncludePaths|null $include = null,
    ): Node|NotFound|BadRequest {
        $include ??= Params\IncludePaths::none();
        $unsupported = $include->unsupported(self::INCLUDE_PATHS);
        if ($unsupported !== null) {
            return $unsupported;
        }
        $subgraph = $this->subgraph($contentRepositoryId, $workspaceName, $dimensionSpacePoint);
        if (!$subgraph instanceof ContentSubgraphInterface) {
            return $subgraph;
        }
        $node = $this->findNode($subgraph, $aggregateId);
        return $node instanceof ContentGraph\Node ? $this->node($node, $include) : $node;
    }

    /**
     * The subgraph with the visibility of the Neos backend, see SubgraphResolver::resolve()
     */
    private function subgraph(ContentRepositoryId $contentRepositoryId, ?WorkspaceName $workspaceName, ?DimensionSpacePoint $dimensionSpacePoint): ContentSubgraphInterface|BadRequest|NotFound
    {
        return $this->subgraphResolver->resolve(
            $contentRepositoryId->toContentRepositoryId(),
            $workspaceName?->toWorkspaceName() ?? SharedModel\Workspace\WorkspaceName::forLive(),
            $dimensionSpacePoint,
            excludeDisabled: false,
        );
    }

    /**
     * The node in the subgraph, a 404 if there is none the account may read
     */
    private function findNode(ContentSubgraphInterface $subgraph, NodeAggregateId $aggregateId): ContentGraph\Node|NotFound
    {
        return $subgraph->findNodeById($aggregateId->toNodeAggregateId())
            ?? NotFound::because(sprintf(
                'There is no node %s in the workspace %s and the dimension space point %s',
                $aggregateId->value,
                $subgraph->getWorkspaceName()->value,
                $subgraph->getDimensionSpacePoint()->toJson(),
            ));
    }

    /**
     * The site node of the content repository's default site in the subgraph, a 404 if there is none the account may
     * read
     */
    private function defaultSiteNode(ContentSubgraphInterface $subgraph): ContentGraph\Node|NotFound
    {
        $site = $this->siteFinder->findDefault($subgraph->getContentRepositoryId());
        if ($site === null) {
            return NotFound::because(sprintf('There is no site in the content repository %s to list the nodes of, give filter[parent], filter[ancestor] or filter[referencing]', $subgraph->getContentRepositoryId()->value));
        }
        return $this->contentSubgraphs->findSiteNodeIn($subgraph, $site)
            ?? NotFound::because(sprintf(
                'There is no site node of the site %s in the workspace %s and the dimension space point %s',
                $site->getNodeName()->value,
                $subgraph->getWorkspaceName()->value,
                $subgraph->getDimensionSpacePoint()->toJson(),
            ));
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
