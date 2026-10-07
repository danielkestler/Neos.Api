<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\Nodes;

use Neos\Api\Endpoint\Nodes\Params\NodeFilter;
use Neos\Api\Shared\Params;
use Neos\Api\Shared\Response\BadRequest;
use Neos\ContentRepository\Core\NodeType\NodeTypeManager;
use Neos\ContentRepository\Core\Projection\ContentGraph;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter;
use Neos\ContentRepository\Core\SharedModel\Node\PropertyName;

/**
 * The query of listNodes in the content repository's terms: the filter, sort and page of the request as the filter of
 * the subgraph query its entry point needs, the child nodes, descendant nodes or back references, descendant
 * nodes without an entry point
 */
final readonly class NodeQuery
{
    /**
     * The sort fields besides properties.<name>, as the node's timestamps
     */
    private const array SORT_TIMESTAMPS = [
        'timestamps.created' => Filter\Ordering\TimestampField::CREATED,
        'timestamps.lastModified' => Filter\Ordering\TimestampField::LAST_MODIFIED,
        'timestamps.originalCreated' => Filter\Ordering\TimestampField::ORIGINAL_CREATED,
        'timestamps.originalLastModified' => Filter\Ordering\TimestampField::ORIGINAL_LAST_MODIFIED,
    ];

    private function __construct(
        private NodeFilter $filter,
        private ?Filter\NodeType\NodeTypeCriteria $nodeTypes,
        private ?Filter\SearchTerm\SearchTerm $searchTerm,
        private ?Filter\PropertyValue\Criteria\PropertyValueCriteriaInterface $propertyValue,
        private ?Filter\Ordering\Ordering $ordering,
        private Filter\Pagination\Pagination $pagination,
    ) {
    }

    /**
     * The query, a 400 if the sort or filter[property] is invalid. The node types are checked by find(), they are
     * the ones of the entry point's content repository
     */
    public static function create(NodeFilter $filter, ?Params\Sort $sort, Params\Page $page): self|BadRequest
    {
        $invalidSort = self::invalidSort($sort);
        if ($invalidSort !== null) {
            return $invalidSort;
        }
        try {
            $propertyValue = $filter->property !== null ? Filter\PropertyValue\PropertyValueCriteriaParser::parse($filter->property) : null;
        } catch (\InvalidArgumentException $exception) {
            return BadRequest::because(sprintf('filter[property] is invalid: %s', $exception->getMessage()));
        }
        return new self(
            $filter,
            $filter->nodeType !== null ? Filter\NodeType\NodeTypeCriteria::fromFilterString($filter->nodeType) : null,
            $filter->search !== null ? Filter\SearchTerm\SearchTerm::fulltext($filter->search) : null,
            $propertyValue,
            self::ordering($sort),
            $page->toPagination(),
        );
    }

    /**
     * The page of nodes found from the entry point and the total, a 400 if a node type of filter[nodeType] doesn't
     * exist
     *
     * @param ContentGraph\Node $entryPoint the node of filter[parent], filter[ancestor] or filter[referencing], else the
     *                                      default site node
     * @param ContentSubgraphInterface $subgraph the one the entry point was found in
     * @return array{list<ContentGraph\Node>, int}|BadRequest
     */
    public function find(ContentGraph\Node $entryPoint, ContentSubgraphInterface $subgraph, NodeTypeManager $nodeTypeManager): array|BadRequest
    {
        $unknownNodeTypes = $this->nodeTypes === null ? [] : array_values(array_filter(
            [...$this->nodeTypes->explicitlyAllowedNodeTypeNames->toStringArray(), ...$this->nodeTypes->explicitlyDisallowedNodeTypeNames->toStringArray()],
            static fn (string $nodeTypeName) => !$nodeTypeManager->hasNodeType($nodeTypeName),
        ));
        if ($unknownNodeTypes !== []) {
            return BadRequest::because(sprintf('There is no node type %s', implode(', ', $unknownNodeTypes)));
        }
        if ($this->filter->parent !== null) {
            $childNodesFilter = Filter\FindChildNodesFilter::create($this->nodeTypes, $this->searchTerm, $this->propertyValue, $this->ordering, $this->pagination);
            return [
                iterator_to_array($subgraph->findChildNodes($entryPoint->aggregateId, $childNodesFilter), false),
                $subgraph->countChildNodes($entryPoint->aggregateId, Filter\CountChildNodesFilter::fromFindChildNodesFilter($childNodesFilter)),
            ];
        }
        if ($this->filter->referencing !== null) {
            $backReferencesFilter = Filter\FindBackReferencesFilter::create(
                nodeTypes: $this->nodeTypes,
                nodeSearchTerm: $this->searchTerm,
                nodePropertyValue: $this->propertyValue,
                referenceName: $this->filter->referenceName,
                ordering: $this->ordering,
                pagination: $this->pagination,
            );
            return [
                array_map(
                    static fn (ContentGraph\Reference $reference) => $reference->node,
                    iterator_to_array($subgraph->findBackReferences($entryPoint->aggregateId, $backReferencesFilter), false),
                ),
                $subgraph->countBackReferences($entryPoint->aggregateId, Filter\CountBackReferencesFilter::fromFindBackReferencesFilter($backReferencesFilter)),
            ];
        }
        // filter[ancestor], or the default site node without an entry point
        $descendantNodesFilter = Filter\FindDescendantNodesFilter::create($this->nodeTypes, $this->searchTerm, $this->propertyValue, $this->ordering, $this->pagination);
        return [
            iterator_to_array($subgraph->findDescendantNodes($entryPoint->aggregateId, $descendantNodesFilter), false),
            $subgraph->countDescendantNodes($entryPoint->aggregateId, Filter\CountDescendantNodesFilter::fromFindDescendantNodesFilter($descendantNodesFilter)),
        ];
    }

    private static function invalidSort(?Params\Sort $sort): ?BadRequest
    {
        $unknown = array_filter(
            array_column($sort?->fields() ?? [], 'field'),
            static fn (string $field) => !isset(self::SORT_TIMESTAMPS[$field]) && preg_match('/^properties\.[a-zA-Z0-9_]+$/', $field) !== 1,
        );
        return $unknown !== [] ? BadRequest::because(sprintf('Can\'t sort by %s, only by properties.<name> and %s', implode(', ', $unknown), implode(', ', array_keys(self::SORT_TIMESTAMPS)))) : null;
    }

    private static function ordering(?Params\Sort $sort): ?Filter\Ordering\Ordering
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
}
