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
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Neos\Domain\Repository\SiteRepository;
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
        private SiteRepository $siteRepository,
    ) {
    }

    #[Operation(
        path: '/nodes',
        method: 'GET',
        summary: 'List nodes',
        description: 'A page of the nodes found from one node, in its workspace and dimension space point: with filter[parent] its direct child nodes (in their order unless sorted), with filter[ancestor] all nodes below it, with filter[referencing] the nodes that reference it, a node once per reference. Without any of them, all nodes below the site node of the default site (Neos.Neos.defaultSiteNodeName, else the first online site), in filter[workspace] (live by default) and filter[dimensionSpacePoint] (the site\'s default one by default), which only apply then: a node address has its own. The other filter members narrow them down. sort takes properties.<name> and timestamps.created, timestamps.lastModified, timestamps.originalCreated, timestamps.originalLastModified. page[offset] and page[limit] (25 by default, 100 at most) choose the page, meta.total and links tell about the others. include works as in getNode, for each node. Hidden nodes are visible as in getNode.',
        operationId: 'listNodes',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::NODES_READ],
        ],
    )]
    public function list(
        ServerRequestInterface $request,
        #[Parameter(in: 'query', description: 'Which nodes: at most one of filter[parent], filter[ancestor] and filter[referencing] (a node address each), all nodes below the site node of the default site without any, in filter[workspace] and filter[dimensionSpacePoint] then, narrowed down by filter[nodeType], filter[search], filter[property] and, with filter[referencing], filter[referenceName]')]
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
        if ($entryPoints !== [] && ($filter->workspace !== null || $filter->dimensionSpacePoint !== null)) {
            return BadRequest::because('filter[workspace] and filter[dimensionSpacePoint] only apply without filter[parent], filter[ancestor] and filter[referencing], whose node address has its own');
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
        $entryPoint = $entryPoints !== [] ? $this->findNode($entryPoints[0]) : $this->defaultSiteNode($filter);
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

    /**
     * The site node of the default site in filter[workspace] and filter[dimensionSpacePoint], by default the live
     * workspace and the site's default dimension space point, with the visibility of the Neos backend. A 400 if the
     * dimension space point is invalid or not one of the content repository, a 404 if there is no site node the
     * account may read
     */
    private function defaultSiteNode(NodeFilter $filter): ContentGraph\Node|BadRequest|NotFound
    {
        $site = $this->siteRepository->findDefault();
        if ($site === null) {
            return NotFound::because('There is no site to list the nodes of, give filter[parent], filter[ancestor] or filter[referencing]');
        }
        $contentRepositoryId = $site->getConfiguration()->contentRepositoryId;
        $workspaceName = $filter->workspace?->toWorkspaceName() ?? WorkspaceName::forLive();
        try {
            $dimensionSpacePoint = $filter->dimensionSpacePoint?->toDimensionSpacePoint() ?? $site->getConfiguration()->defaultDimensionSpacePoint;
        } catch (\InvalidArgumentException | \RuntimeException | \TypeError $exception) {
            return BadRequest::because(sprintf('filter[dimensionSpacePoint] is invalid: %s', $exception->getMessage()));
        }
        if (!$this->contentRepositoryRegistry->get($contentRepositoryId)->getVariationGraph()->getDimensionSpacePoints()->contains($dimensionSpacePoint)) {
            return BadRequest::because(sprintf('There is no dimension space point %s in the content repository %s, GET /contentrepositories lists its dimensions and their values', $dimensionSpacePoint->toJson(), $contentRepositoryId->value));
        }
        return $this->contentSubgraphs->findSiteNode($site, excludeDisabled: false, workspaceName: $workspaceName, dimensionSpacePoint: $dimensionSpacePoint)
            ?? NotFound::because(sprintf(
                'There is no site node of the default site %s in the workspace %s and the dimension space point %s',
                $site->getNodeName()->value,
                $workspaceName->value,
                $dimensionSpacePoint->toJson(),
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
