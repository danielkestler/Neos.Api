<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\Nodes;

use Neos\Api\Endpoint\ContentRepositories\Schema\ContentRepositoryId;
use Neos\Api\Endpoint\Nodes\Parameter\HierarchyFilter;
use Neos\Api\Endpoint\Nodes\Parameter\NodeTypeCriteria;
use Neos\Api\Endpoint\Nodes\Parameter\PropertyCriteria;
use Neos\Api\Endpoint\Nodes\Parameter\ReferenceFilter;
use Neos\Api\Endpoint\Nodes\Parameter\SearchTerm;
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
use Neos\Api\Shared\Parameter\IncludePaths;
use Neos\Api\Shared\Parameter\Limit;
use Neos\Api\Shared\Parameter\Offset;
use Neos\Api\Shared\Parameter\Sort;
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
        description: 'A page of the nodes in the workspace and dimension space point: with filterByHierarchy the nodes below a node (type parent its direct child nodes, in their order unless sorted, type ancestor all of them), with filterByReference the nodes that reference a node, a node once per reference. The two can\'t be combined, each is a query of its own. Without either, all nodes below the site node of the content repository\'s default site (Neos.Neos.defaultSiteNodeName if it is in the content repository, else its first online site by name). filterByNodeType, filterByProperty and search narrow them down. sort takes properties.<name> and timestamps.created, timestamps.lastModified, timestamps.originalCreated, timestamps.originalLastModified. offset and limit (25 by default, 100 at most) choose the page, meta.total and links tell about the others. include works as in getNode, for each node. Hidden nodes are visible as in getNode.',
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
        #[Parameter(in: 'query', description: 'The nodes below a node: filterByHierarchy[type] parent (its direct child nodes) or ancestor (all nodes below it) and filterByHierarchy[nodeAggregateId]. Not with filterByReference')]
        HierarchyFilter|null $filterByHierarchy = null,
        #[Parameter(in: 'query', description: 'The nodes that reference a node: filterByReference[nodeAggregateId], optionally filterByReference[name] for the references of that name only. Not with filterByHierarchy')]
        ReferenceFilter|null $filterByReference = null,
        #[Parameter(in: 'query', description: 'Only nodes of these node types or ones inheriting from them, comma-separated, a ! in front excludes a type and the ones inheriting from it. Unknown node types are a 400')]
        NodeTypeCriteria|null $filterByNodeType = null,
        #[Parameter(in: 'query', description: 'Only nodes whose properties match, in the content repository\'s syntax, e.g. title *= \'Neos\' AND NOT (hideInMenu = true)')]
        PropertyCriteria|null $filterByProperty = null,
        #[Parameter(in: 'query', description: 'Only nodes with a property containing this text')]
        SearchTerm|null $search = null,
        #[Parameter(in: 'query', description: 'The fields to sort by, comma-separated, each ascending unless prefixed with -: properties.<name>, timestamps.created, timestamps.lastModified, timestamps.originalCreated, timestamps.originalLastModified')]
        Sort|null $sort = null,
        #[Parameter(in: 'query', description: 'How many items to skip, 0 if omitted')]
        Offset|null $offset = null,
        #[Parameter(in: 'query', description: 'How many items at most, 25 if omitted, 100 at most')]
        Limit|null $limit = null,
        #[Parameter(in: 'query', description: 'What to include beyond each node\'s own fields, comma-separated: references, children, children.references, variants, variants.references')]
        IncludePaths|null $include = null,
    ): PaginatedNodeListing|NotFound|BadRequest {
        $offset ??= Offset::none();
        $limit ??= Limit::default();
        $include ??= IncludePaths::none();
        $unsupported = $include->unsupported(self::INCLUDE_PATHS);
        if ($unsupported !== null) {
            return $unsupported;
        }
        $query = NodeQuery::create($filterByHierarchy, $filterByReference, $filterByNodeType, $filterByProperty, $search, $sort, $offset, $limit);
        if ($query instanceof BadRequest) {
            return $query;
        }
        $subgraph = $this->subgraph($contentRepositoryId, $workspaceName, $dimensionSpacePoint);
        if (!$subgraph instanceof ContentSubgraphInterface) {
            return $subgraph;
        }
        $entryPointId = $query->entryPoint();
        $entryPoint = $entryPointId !== null ? $this->findNode($subgraph, $entryPointId) : $this->defaultSiteNode($subgraph);
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
            ListingLinks::for($request, $offset, $limit, $total),
        );
    }

    #[Operation(
        path: '/cr/{contentRepositoryId}/nodes/{nodeAggregateId}',
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
        NodeAggregateId $nodeAggregateId,
        #[Parameter(in: 'query', description: 'The workspace to read the node in, live if omitted')]
        WorkspaceName|null $workspaceName = null,
        #[Parameter(in: 'query', description: 'The dimension space point to read the node in, as JSON. If omitted, the default one of the content repository\'s default site (Neos.Neos.defaultSiteNodeName if it is in the content repository, else its first online site by name), the only one of a content repository without a site')]
        DimensionSpacePoint|null $dimensionSpacePoint = null,
        #[Parameter(in: 'query', description: 'What to include beyond the node\'s own fields, comma-separated: references, children, children.references, variants, variants.references')]
        IncludePaths|null $include = null,
    ): Node|NotFound|BadRequest {
        $include ??= IncludePaths::none();
        $unsupported = $include->unsupported(self::INCLUDE_PATHS);
        if ($unsupported !== null) {
            return $unsupported;
        }
        $subgraph = $this->subgraph($contentRepositoryId, $workspaceName, $dimensionSpacePoint);
        if (!$subgraph instanceof ContentSubgraphInterface) {
            return $subgraph;
        }
        $node = $this->findNode($subgraph, $nodeAggregateId);
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
    private function findNode(ContentSubgraphInterface $subgraph, NodeAggregateId $nodeAggregateId): ContentGraph\Node|NotFound
    {
        return $subgraph->findNodeById($nodeAggregateId->toNodeAggregateId())
            ?? NotFound::because(sprintf(
                'There is no node %s in the workspace %s and the dimension space point %s',
                $nodeAggregateId->value,
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
            return NotFound::because(sprintf('There is no site in the content repository %s to list the nodes of, give filterByHierarchy or filterByReference', $subgraph->getContentRepositoryId()->value));
        }
        return $this->contentSubgraphs->findSiteNodeIn($subgraph, $site)
            ?? NotFound::because(sprintf(
                'There is no site node of the site %s in the workspace %s and the dimension space point %s',
                $site->getNodeName()->value,
                $subgraph->getWorkspaceName()->value,
                $subgraph->getDimensionSpacePoint()->toJson(),
            ));
    }

    private function node(ContentGraph\Node $node, IncludePaths $include): Node
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
