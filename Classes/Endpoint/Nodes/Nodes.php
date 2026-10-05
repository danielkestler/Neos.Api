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
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\Projection\ContentGraph;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindChildNodesFilter;
use Neos\ContentRepository\Core\SharedModel\Node\PropertyName;
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

    /**
     * The sort fields besides properties.<name>, as the node's timestamps
     */
    private const array SORT_TIMESTAMPS = [
        'timestamps.created' => Filter\Ordering\TimestampField::CREATED,
        'timestamps.lastModified' => Filter\Ordering\TimestampField::LAST_MODIFIED,
        'timestamps.originalCreated' => Filter\Ordering\TimestampField::ORIGINAL_CREATED,
        'timestamps.originalLastModified' => Filter\Ordering\TimestampField::ORIGINAL_LAST_MODIFIED,
    ];

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
        $includePaths = $include?->paths() ?? [];
        $entryPoints = $filter?->entryPoints() ?? [];
        if ($filter === null || count($entryPoints) !== 1) {
            return BadRequest::because('Exactly one of filter[parent], filter[ancestor] and filter[referencing] is required');
        }
        if ($filter->referenceName !== null && $filter->referencing === null) {
            return BadRequest::because('filter[referenceName] needs filter[referencing]');
        }
        $invalid = $this->unknownIncludePaths($includePaths) ?? $this->invalidSort($sort);
        if ($invalid !== null) {
            return $invalid;
        }
        try {
            $propertyValue = $filter->property !== null ? Filter\PropertyValue\PropertyValueCriteriaParser::parse($filter->property) : null;
        } catch (\InvalidArgumentException $exception) {
            return BadRequest::because(sprintf('filter[property] is invalid: %s', $exception->getMessage()));
        }
        try {
            $address = $entryPoints[0]->toNodeAddress();
        } catch (\InvalidArgumentException | \TypeError $exception) {
            return BadRequest::because(sprintf('The node address is invalid: %s', $exception->getMessage()));
        }
        $subgraph = $this->contentSubgraphs->find($address->contentRepositoryId, $address->workspaceName, $address->dimensionSpacePoint, excludeDisabled: false);
        if ($subgraph === null || $subgraph->findNodeById($address->aggregateId) === null) {
            return NotFound::because(sprintf('There is no node %s', $address->toJson()));
        }
        $nodeTypes = $filter->nodeType !== null ? Filter\NodeType\NodeTypeCriteria::fromFilterString($filter->nodeType) : null;
        $nodeTypeManager = $this->contentRepositoryRegistry->get($address->contentRepositoryId)->getNodeTypeManager();
        $unknownNodeTypes = $nodeTypes === null ? [] : array_values(array_filter(
            [...$nodeTypes->explicitlyAllowedNodeTypeNames->toStringArray(), ...$nodeTypes->explicitlyDisallowedNodeTypeNames->toStringArray()],
            static fn (string $nodeTypeName) => !$nodeTypeManager->hasNodeType($nodeTypeName),
        ));
        if ($unknownNodeTypes !== []) {
            return BadRequest::because(sprintf('There is no node type %s', implode(', ', $unknownNodeTypes)));
        }
        $searchTerm = $filter->search !== null ? Filter\SearchTerm\SearchTerm::fulltext($filter->search) : null;
        $ordering = $this->ordering($sort);
        $pagination = $page->toPagination();

        if ($filter->parent !== null) {
            $childNodesFilter = FindChildNodesFilter::create($nodeTypes, $searchTerm, $propertyValue, $ordering, $pagination);
            $nodes = iterator_to_array($subgraph->findChildNodes($address->aggregateId, $childNodesFilter), false);
            $total = $subgraph->countChildNodes($address->aggregateId, Filter\CountChildNodesFilter::fromFindChildNodesFilter($childNodesFilter));
        } elseif ($filter->ancestor !== null) {
            $descendantNodesFilter = Filter\FindDescendantNodesFilter::create($nodeTypes, $searchTerm, $propertyValue, $ordering, $pagination);
            $nodes = iterator_to_array($subgraph->findDescendantNodes($address->aggregateId, $descendantNodesFilter), false);
            $total = $subgraph->countDescendantNodes($address->aggregateId, Filter\CountDescendantNodesFilter::fromFindDescendantNodesFilter($descendantNodesFilter));
        } else {
            $backReferencesFilter = Filter\FindBackReferencesFilter::create(
                nodeTypes: $nodeTypes,
                nodeSearchTerm: $searchTerm,
                nodePropertyValue: $propertyValue,
                referenceName: $filter->referenceName,
                ordering: $ordering,
                pagination: $pagination,
            );
            $nodes = array_map(
                static fn (ContentGraph\Reference $reference) => $reference->node,
                iterator_to_array($subgraph->findBackReferences($address->aggregateId, $backReferencesFilter), false),
            );
            $total = $subgraph->countBackReferences($address->aggregateId, Filter\CountBackReferencesFilter::fromFindBackReferencesFilter($backReferencesFilter));
        }
        return new PaginatedNodeListing(
            new NodeList(...array_map(fn (ContentGraph\Node $node) => $this->node($node, $subgraph, $includePaths), $nodes)),
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
        $includePaths = $include?->paths() ?? [];
        $unknown = $this->unknownIncludePaths($includePaths);
        if ($unknown !== null) {
            return $unknown;
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
        return $this->node($node, $subgraph, $includePaths);
    }

    /**
     * @param list<string> $includePaths
     */
    private function unknownIncludePaths(array $includePaths): ?BadRequest
    {
        $unknown = array_diff($includePaths, self::INCLUDE_PATHS);
        return $unknown !== [] ? BadRequest::because(sprintf('Can\'t include %s, only: %s', implode(', ', $unknown), implode(', ', self::INCLUDE_PATHS))) : null;
    }

    private function invalidSort(?Params\Sort $sort): ?BadRequest
    {
        $unknown = array_filter(
            array_column($sort?->fields() ?? [], 'field'),
            static fn (string $field) => !isset(self::SORT_TIMESTAMPS[$field]) && preg_match('/^properties\.[a-zA-Z0-9_]+$/', $field) !== 1,
        );
        return $unknown !== [] ? BadRequest::because(sprintf('Can\'t sort by %s, only by properties.<name> and %s', implode(', ', $unknown), implode(', ', array_keys(self::SORT_TIMESTAMPS)))) : null;
    }

    private function ordering(?Params\Sort $sort): ?Filter\Ordering\Ordering
    {
        $ordering = null;
        foreach ($sort?->fields() ?? [] as ['field' => $field, 'descending' => $descending]) {
            $direction = $descending ? Filter\Ordering\OrderingDirection::DESCENDING : Filter\Ordering\OrderingDirection::ASCENDING;
            $timestampField = self::SORT_TIMESTAMPS[$field] ?? null;
            $ordering = match (true) {
                $timestampField !== null && $ordering === null => Filter\Ordering\Ordering::byTimestampField($timestampField, $direction),
                $timestampField !== null => $ordering->andByTimestampField($timestampField, $direction),
                $ordering === null => Filter\Ordering\Ordering::byProperty(PropertyName::fromString(substr($field, strlen('properties.'))), $direction),
                default => $ordering->andByProperty(PropertyName::fromString(substr($field, strlen('properties.'))), $direction),
            };
        }
        return $ordering;
    }

    /**
     * @param list<string> $includePaths
     */
    private function node(ContentGraph\Node $node, ContentSubgraphInterface $subgraph, array $includePaths): Node
    {
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
