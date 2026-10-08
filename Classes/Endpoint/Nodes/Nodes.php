<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\Nodes;

use Neos\Api\Endpoint\ContentRepositories\Schema\ContentRepositoryId;
use Neos\Api\Endpoint\Nodes\Parameter\HierarchyFilter;
use Neos\Api\Endpoint\Nodes\Parameter\NodeTypeCriteria;
use Neos\Api\Endpoint\Nodes\Parameter\PropertyCriteria;
use Neos\Api\Endpoint\Nodes\Parameter\ReferenceFilter;
use Neos\Api\Endpoint\Nodes\Parameter\SearchTerm;
use Neos\Api\Endpoint\Nodes\RequestBody\NodeCreate;
use Neos\Api\Endpoint\Nodes\RequestBody\NodeMove;
use Neos\Api\Endpoint\Nodes\RequestBody\NodePropertiesUpdate;
use Neos\Api\Endpoint\Nodes\RequestBody\NodeReferencesUpdate;
use Neos\Api\Endpoint\Nodes\RequestBody\NodeTag;
use Neos\Api\Endpoint\Nodes\RequestBody\NodeTypeChange;
use Neos\Api\Endpoint\Nodes\Response\NodeCreated;
use Neos\Api\Endpoint\Nodes\Schema\DimensionSpacePoint;
use Neos\Api\Endpoint\Nodes\Schema\Node;
use Neos\Api\Endpoint\Nodes\Schema\NodeAggregateId;
use Neos\Api\Endpoint\Nodes\Schema\NodeList;
use Neos\Api\Endpoint\Nodes\Schema\PaginatedNodeListing;
use Neos\Api\Endpoint\Nodes\Schema\SubtreeTag;
use Neos\Api\Endpoint\Workspaces\ReadableWorkspace;
use Neos\Api\Endpoint\Workspaces\Schema\WorkspaceName;
use Neos\Api\Endpoint\Workspaces\WorkspaceResolver;
use Neos\Api\Infrastructure\ContentRepository\ContentSubgraphs;
use Neos\Api\Infrastructure\ContentRepository\InvalidPropertyValue;
use Neos\Api\Infrastructure\ContentRepository\NodeSerializer;
use Neos\Api\Infrastructure\ContentRepository\PropertyValues;
use Neos\Api\Infrastructure\ContentRepository\UriPathSegments;
use Neos\Api\Infrastructure\ContentRepository\SiteFinder;
use Neos\Api\Security\ApiAuthContextProvider;
use Neos\Api\Security\ApiCaller;
use Neos\Api\Security\ApiScopes;
use Neos\Api\Shared\Parameter\IncludePaths;
use Neos\Api\Shared\Parameter\Limit;
use Neos\Api\Shared\Parameter\Offset;
use Neos\Api\Shared\Parameter\Sort;
use Neos\Api\Shared\Response\BadRequest;
use Neos\Api\Shared\Response\Conflict;
use Neos\Api\Shared\Response\Forbidden;
use Neos\Api\Shared\Response\NotFound;
use Neos\Api\Shared\Response\UnprocessableContent;
use Neos\Api\Shared\Schema\ListingLinks;
use Neos\Api\Shared\Schema\ListingMeta;
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\Feature\NodeCreation\Command\CreateNodeAggregateWithNode;
use Neos\ContentRepository\Core\Feature\NodeModification\Command\SetNodeProperties;
use Neos\ContentRepository\Core\Feature\NodeReferencing\Command\SetNodeReferences;
use Neos\ContentRepository\Core\Feature\NodeMove\Command\MoveNodeAggregate;
use Neos\ContentRepository\Core\Feature\NodeMove\Dto\RelationDistributionStrategy;
use Neos\ContentRepository\Core\Feature\NodeTypeChange\Command\ChangeNodeAggregateType;
use Neos\ContentRepository\Core\Feature\NodeTypeChange\Dto\NodeAggregateTypeChangeChildConstraintConflictResolutionMarkWithTagStrategy;
use Neos\ContentRepository\Core\Feature\Security\Exception\AccessDenied;
use Neos\ContentRepository\Core\Feature\SubtreeTagging\Command\TagSubtree;
use Neos\ContentRepository\Core\Feature\SubtreeTagging\Command\UntagSubtree;
use Neos\ContentRepository\Core\Feature\SubtreeTagging\Exception\SubtreeIsAlreadyTagged;
use Neos\ContentRepository\Core\Feature\SubtreeTagging\Exception\SubtreeIsNotTagged;
use Neos\ContentRepository\Core\NodeType;
use Neos\ContentRepository\Core\Projection\ContentGraph;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentGraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\NodeAggregate;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindChildNodesFilter;
use Neos\ContentRepository\Core\SharedModel;
use Neos\ContentRepository\Core\SharedModel\Exception\DimensionSpacePointIsNotYetOccupied;
use Neos\ContentRepository\Core\SharedModel\Exception\NodeAggregateCurrentlyDoesNotExist;
use Neos\ContentRepository\Core\SharedModel\Exception\NodeAggregateCurrentlyExists;
use Neos\ContentRepository\Core\SharedModel\Exception\NodeAggregateDoesCurrentlyNotCoverDimensionSpacePoint;
use Neos\ContentRepository\Core\SharedModel\Exception\NodeAggregateDoesCurrentlyNotCoverDimensionSpacePointSet;
use Neos\ContentRepository\Core\SharedModel\Exception\NodeAggregateIsDescendant;
use Neos\ContentRepository\Core\SharedModel\Exception\NodeAggregateIsNoChild;
use Neos\ContentRepository\Core\SharedModel\Exception\NodeAggregateIsNoSibling;
use Neos\ContentRepository\Core\SharedModel\Exception\NodeAggregateIsRoot;
use Neos\ContentRepository\Core\SharedModel\Exception\NodeAggregateIsTethered;
use Neos\ContentRepository\Core\SharedModel\Exception\NodeAggregateIsUntethered;
use Neos\ContentRepository\Core\SharedModel\Exception\NodeConstraintException;
use Neos\ContentRepository\Core\SharedModel\Exception\NodeNameIsAlreadyCovered;
use Neos\ContentRepository\Core\SharedModel\Exception\NodeTypeIsAbstract;
use Neos\ContentRepository\Core\SharedModel\Exception\NodeTypeIsOfTypeRoot;
use Neos\ContentRepository\Core\SharedModel\Exception\PropertyCannotBeSet;
use Neos\ContentRepository\Core\SharedModel\Exception\ReferenceCannotBeSet;
use Neos\ContentRepository\Core\SharedModel\Node\NodeVariantSelectionStrategy;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Neos\Domain\SubtreeTagging\NeosSubtreeTag;
use Neos\OpenApi\Attributes\AuthContext;
use Neos\OpenApi\Attributes\Operation;
use Neos\OpenApi\Attributes\Parameter;
use Neos\OpenApi\Attributes\RequestBody;
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
        private PropertyValues $propertyValues,
        private UriPathSegments $uriPathSegments,
        private WorkspaceResolver $workspaceResolver,
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
        path: '/cr/{contentRepositoryId}/nodes',
        method: 'POST',
        summary: 'Create a node',
        description: 'Creates a node of the node type below the parent in the workspace, with the dimension space point as its origin (CreateNodeAggregateWithNode): before the succeeding sibling, else as the parent\'s last child, with the given aggregate id, else a new one. The properties are set as for updateNodeProperties, over the node type\'s defaults, a document without uriPathSegment gets one from its title as in the Neos backend. Tethered child nodes (e.g. a page\'s main collection) are created with it. An unknown, abstract or root node type, one the parent doesn\'t allow below it, a sibling that isn\'t a child of the parent or properties that can\'t be written are a 422, an aggregate id that is taken a 409. Whether the account may create the node there is up to its workspace roles and node privileges (else a 403), as in the Neos backend. The response is the new node as getNode returns it without include.',
        operationId: 'createNode',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::NODES_CREATE],
        ],
    )]
    public function create(
        ContentRepositoryId $contentRepositoryId,
        #[Parameter(in: 'query', description: 'The workspace to create the node in, required: changing live directly is rarely what is meant')]
        WorkspaceName $workspaceName,
        #[RequestBody(description: 'The node')] NodeCreate $newNode,
        #[Parameter(in: 'query', description: 'The dimension space point to create the node in, its origin, as JSON. If omitted, as for getNode')]
        DimensionSpacePoint|null $dimensionSpacePoint = null,
    ): NodeCreated|NotFound|BadRequest|Forbidden|Conflict|UnprocessableContent {
        $subgraph = $this->subgraph($contentRepositoryId, $workspaceName, $dimensionSpacePoint);
        if (!$subgraph instanceof ContentSubgraphInterface) {
            return $subgraph;
        }
        $parent = $this->findNode($subgraph, $newNode->parentNodeAggregateId);
        if (!$parent instanceof ContentGraph\Node) {
            return $parent;
        }
        // the content repository doesn't check it, the projection would take the position below the sibling's parent
        if ($newNode->succeedingSiblingNodeAggregateId !== null && $subgraph->findParentNode($newNode->succeedingSiblingNodeAggregateId->toNodeAggregateId())?->aggregateId->equals($parent->aggregateId) !== true) {
            return UnprocessableContent::because(sprintf('The node %s has no child node %s to create the node before', $parent->aggregateId->value, $newNode->succeedingSiblingNodeAggregateId->value));
        }
        $contentRepository = $this->contentRepositoryRegistry->get($parent->contentRepositoryId);
        $nodeTypeName = NodeType\NodeTypeName::fromString($newNode->nodeType->value);
        $nodeType = $contentRepository->getNodeTypeManager()->getNodeType($nodeTypeName);
        if ($nodeType === null) {
            return UnprocessableContent::because(sprintf('There is no node type %s', $nodeTypeName->value));
        }
        $values = $newNode->properties;
        if ($nodeType->isOfType('Neos.Neos:Document') && $nodeType->hasProperty('uriPathSegment') && !isset($values['uriPathSegment'])) {
            $values['uriPathSegment'] = $this->uriPathSegments->forNewDocument($nodeTypeName, is_string($values['title'] ?? null) ? $values['title'] : null, $subgraph->getDimensionSpacePoint());
        }
        try {
            $propertyValues = $this->propertyValues->toWrite($nodeType, $values);
        } catch (InvalidPropertyValue $exception) {
            return UnprocessableContent::because($exception->getMessage());
        }
        $nodeAggregateId = $newNode->nodeAggregateId?->toNodeAggregateId() ?? SharedModel\Node\NodeAggregateId::create();
        try {
            $contentRepository->handle(CreateNodeAggregateWithNode::create(
                $parent->workspaceName,
                $nodeAggregateId,
                $nodeTypeName,
                OriginDimensionSpacePoint::fromDimensionSpacePoint($subgraph->getDimensionSpacePoint()),
                $parent->aggregateId,
                $newNode->succeedingSiblingNodeAggregateId?->toNodeAggregateId(),
                $propertyValues,
            ));
        } catch (AccessDenied) {
            return Forbidden::because(sprintf('You may not create a node below the node %s in the workspace %s', $parent->aggregateId->value, $parent->workspaceName->value));
        } catch (NodeAggregateCurrentlyExists) {
            return Conflict::because(sprintf('There is a node with the aggregate id %s already', $nodeAggregateId->value));
        } catch (NodeAggregateDoesCurrentlyNotCoverDimensionSpacePoint) {
            return Conflict::because(sprintf('The node %s isn\'t in the dimension space point %s', $parent->aggregateId->value, $subgraph->getDimensionSpacePoint()->toJson()));
        } catch (NodeTypeIsAbstract | NodeTypeIsOfTypeRoot | NodeConstraintException | PropertyCannotBeSet $exception) {
            return UnprocessableContent::because($exception->getMessage());
        }
        $created = $this->findNode($this->contentRepositoryRegistry->subgraphForNode($parent), NodeAggregateId::fromString($nodeAggregateId->value));
        return $created instanceof ContentGraph\Node ? new NodeCreated($this->node($created, IncludePaths::none())) : $created;
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

    #[Operation(
        path: '/cr/{contentRepositoryId}/nodes/{nodeAggregateId}/properties',
        method: 'PATCH',
        summary: 'Change the properties of a node',
        description: 'Sets the given properties of the node in the workspace and dimension space point (SetNodeProperties) and leaves the others as they are, null unsets one. The body is the properties by name, the response the node as getNode returns it without include. Properties the node type doesn\'t declare or values that don\'t fit their type are a 422. A node shown with the content of another dimension space point (isShineThrough) is a 409: create a variant in this one first. Whether the account may change the node in the workspace is up to its workspace roles and node privileges (else a 403), as in the Neos backend.',
        operationId: 'updateNodeProperties',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::NODES_UPDATE],
        ],
    )]
    public function updateProperties(
        ContentRepositoryId $contentRepositoryId,
        NodeAggregateId $nodeAggregateId,
        #[Parameter(in: 'query', description: 'The workspace to change the node in, required: changing live directly is rarely what is meant')]
        WorkspaceName $workspaceName,
        #[RequestBody(description: 'The properties to change by name')] NodePropertiesUpdate $properties,
        ServerRequestInterface $request,
        #[Parameter(in: 'query', description: 'The dimension space point to change the node in, as JSON. If omitted, as for getNode')]
        DimensionSpacePoint|null $dimensionSpacePoint = null,
    ): Node|NotFound|BadRequest|Forbidden|Conflict|UnprocessableContent {
        $subgraph = $this->subgraph($contentRepositoryId, $workspaceName, $dimensionSpacePoint);
        if (!$subgraph instanceof ContentSubgraphInterface) {
            return $subgraph;
        }
        $node = $this->findNode($subgraph, $nodeAggregateId);
        if (!$node instanceof ContentGraph\Node) {
            return $node;
        }
        $contentRepository = $this->contentRepositoryRegistry->get($node->contentRepositoryId);
        $nodeType = $contentRepository->getNodeTypeManager()->getNodeType($node->nodeTypeName);
        if ($nodeType === null) {
            return UnprocessableContent::because(sprintf('The node type %s of the node doesn\'t exist anymore', $node->nodeTypeName->value));
        }
        // NodePropertiesUpdate only validated the body, a map schematic can't build
        /** @var array<string, mixed> $values */
        $values = json_decode((string)$request->getBody(), true, flags: JSON_THROW_ON_ERROR);
        try {
            $propertyValues = $this->propertyValues->toWrite($nodeType, $values);
        } catch (InvalidPropertyValue $exception) {
            return UnprocessableContent::because($exception->getMessage());
        }
        // the content repository rejects a command without changes
        if ($values !== []) {
            try {
                // in the requested point, not the node's origin: the content repository decides whether it may be changed there
                $contentRepository->handle(SetNodeProperties::create($node->workspaceName, $node->aggregateId, OriginDimensionSpacePoint::fromDimensionSpacePoint($node->dimensionSpacePoint), $propertyValues));
            } catch (AccessDenied) {
                return Forbidden::because(sprintf('You may not change the node %s in the workspace %s', $node->aggregateId->value, $node->workspaceName->value));
            } catch (DimensionSpacePointIsNotYetOccupied) {
                return Conflict::because(sprintf(
                    'The node is shown in the dimension space point %s with the content of %s, create a variant in %1$s first',
                    $node->dimensionSpacePoint->toJson(),
                    $node->originDimensionSpacePoint->toJson(),
                ));
            } catch (PropertyCannotBeSet $exception) {
                // PropertyValues checks the same, in case they disagree
                return UnprocessableContent::because($exception->getMessage());
            }
        }
        // the node is read-only, read it again
        $changed = $this->findNode($this->contentRepositoryRegistry->subgraphForNode($node), $nodeAggregateId);
        return $changed instanceof ContentGraph\Node ? $this->node($changed, IncludePaths::none()) : $changed;
    }

    #[Operation(
        path: '/cr/{contentRepositoryId}/nodes/{nodeAggregateId}/references',
        method: 'PATCH',
        summary: 'Change the references of a node',
        description: 'Sets the given references of the node in the workspace and dimension space point (SetNodeReferences): each name\'s list replaces all of its references, in its order, an empty list removes them, the names left out stay as they are. The body is the references by name as Node has them, e.g. {"relatedPages": [{"nodeAggregateId": "…"}]}, a reference\'s properties are optional and in the form of node properties. References the node type doesn\'t declare, targets that don\'t exist or aren\'t in the dimension space point, ones the reference doesn\'t allow (its node types or maxItems), or properties that can\'t be written are a 422. A node shown with the content of another dimension space point (isShineThrough) is a 409: create a variant in this one first. Whether the account may change the node is up to its workspace roles and node privileges (else a 403), as in the Neos backend. The response is the node with its references, as getNode returns it with include=references.',
        operationId: 'updateNodeReferences',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::NODES_UPDATE],
        ],
    )]
    public function updateReferences(
        ContentRepositoryId $contentRepositoryId,
        NodeAggregateId $nodeAggregateId,
        #[Parameter(in: 'query', description: 'The workspace to change the node in, required: changing live directly is rarely what is meant')]
        WorkspaceName $workspaceName,
        #[RequestBody(description: 'The references to change by name')] NodeReferencesUpdate $references,
        ServerRequestInterface $request,
        #[Parameter(in: 'query', description: 'The dimension space point to change the node in, as JSON. If omitted, as for getNode')]
        DimensionSpacePoint|null $dimensionSpacePoint = null,
    ): Node|NotFound|BadRequest|Forbidden|Conflict|UnprocessableContent {
        $node = $this->nodeIn($contentRepositoryId, $nodeAggregateId, $workspaceName, $dimensionSpacePoint);
        if (!$node instanceof ContentGraph\Node) {
            return $node;
        }
        $contentRepository = $this->contentRepositoryRegistry->get($node->contentRepositoryId);
        $nodeType = $contentRepository->getNodeTypeManager()->getNodeType($node->nodeTypeName);
        if ($nodeType === null) {
            return UnprocessableContent::because(sprintf('The node type %s of the node doesn\'t exist anymore', $node->nodeTypeName->value));
        }
        // NodeReferencesUpdate only validated the body, a map schematic can't build
        /** @var array<string, mixed> $values */
        $values = json_decode((string)$request->getBody(), true, flags: JSON_THROW_ON_ERROR);
        try {
            $referencesToWrite = $this->propertyValues->referencesToWrite($nodeType, $values);
        } catch (InvalidPropertyValue $exception) {
            return UnprocessableContent::because($exception->getMessage());
        }
        // the content repository rejects a command without changes
        if ($values !== []) {
            try {
                // in the requested point, as updateProperties: the content repository decides whether it may be changed there
                $contentRepository->handle(SetNodeReferences::create($node->workspaceName, $node->aggregateId, OriginDimensionSpacePoint::fromDimensionSpacePoint($node->dimensionSpacePoint), $referencesToWrite));
            } catch (AccessDenied) {
                return Forbidden::because(sprintf('You may not change the node %s in the workspace %s', $node->aggregateId->value, $node->workspaceName->value));
            } catch (DimensionSpacePointIsNotYetOccupied) {
                return Conflict::because(sprintf(
                    'The node is shown in the dimension space point %s with the content of %s, create a variant in %1$s first',
                    $node->dimensionSpacePoint->toJson(),
                    $node->originDimensionSpacePoint->toJson(),
                ));
            } catch (ReferenceCannotBeSet | NodeAggregateCurrentlyDoesNotExist | NodeAggregateDoesCurrentlyNotCoverDimensionSpacePoint | NodeAggregateIsRoot $exception) {
                return UnprocessableContent::because($exception->getMessage());
            }
        }
        $changed = $this->findNode($this->contentRepositoryRegistry->subgraphForNode($node), $nodeAggregateId);
        return $changed instanceof ContentGraph\Node ? $this->node($changed, IncludePaths::fromString('references')) : $changed;
    }

    #[Operation(
        path: '/cr/{contentRepositoryId}/nodes/{nodeAggregateId}',
        method: 'DELETE',
        summary: 'Delete a node',
        description: 'Removes the node with everything below it in the workspace and dimension space point and the points that fall back to it (e.g. en_UK showing en_US), not in the others, as the Neos backend does: it is soft removed (tagged removed), and becomes a hard removal once it is published and no other workspace has changes to it, after a grace period (Neos.Neos.softRemoval.garbageCollectionGracePeriod), until then restoreNode undoes it. Removed nodes are read nowhere, a removed node is a 404 as an unknown one. A node shown with the content of another point is removed in this one only, it doesn\'t show the other\'s content again. Tethered nodes (e.g. a page\'s main collection) can only be removed with their parent, they and root nodes are a 422. Whether the account may remove the node is up to its workspace roles and node privileges (else a 403), as in the Neos backend.',
        operationId: 'deleteNode',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::NODES_DELETE],
        ],
    )]
    public function delete(
        ContentRepositoryId $contentRepositoryId,
        NodeAggregateId $nodeAggregateId,
        #[Parameter(in: 'query', description: 'The workspace to delete the node in, required: changing live directly is rarely what is meant')]
        WorkspaceName $workspaceName,
        #[Parameter(in: 'query', description: 'The dimension space point to delete the node in (and the points that fall back to it), as JSON. If omitted, as for getNode')]
        DimensionSpacePoint|null $dimensionSpacePoint = null,
    ): NotFound|BadRequest|Forbidden|UnprocessableContent|null {
        $subgraph = $this->subgraph($contentRepositoryId, $workspaceName, $dimensionSpacePoint);
        if (!$subgraph instanceof ContentSubgraphInterface) {
            return $subgraph;
        }
        $node = $this->findNode($subgraph, $nodeAggregateId);
        if (!$node instanceof ContentGraph\Node) {
            return $node;
        }
        try {
            // Neos soft removes, never RemoveNodeAggregate in a workspace, see NeosSubtreeTag::removed()
            $this->contentRepositoryRegistry->get($node->contentRepositoryId)->handle(TagSubtree::create(
                $node->workspaceName,
                $node->aggregateId,
                $node->dimensionSpacePoint,
                NodeVariantSelectionStrategy::STRATEGY_ALL_SPECIALIZATIONS,
                NeosSubtreeTag::removed(),
            ));
        } catch (AccessDenied) {
            return Forbidden::because(sprintf('You may not delete the node %s in the workspace %s', $node->aggregateId->value, $node->workspaceName->value));
        } catch (NodeAggregateIsTethered) {
            return UnprocessableContent::because(sprintf('The node %s is tethered to its parent, it can only be deleted with it', $node->aggregateId->value));
        } catch (NodeAggregateIsRoot) {
            return UnprocessableContent::because(sprintf('The node %s is a root node, which can\'t be deleted', $node->aggregateId->value));
        }
        return null;
    }

    #[Operation(
        path: '/cr/{contentRepositoryId}/nodes/{nodeAggregateId}/tags',
        method: 'POST',
        summary: 'Tag a node',
        description: 'Tags the node and everything below it in the workspace and dimension space point and the points that fall back to it (TagSubtree), as the Neos backend does: disabled hides it (isHidden), other tags may be matched by node privileges. removed is a 422, deleteNode removes nodes. A tag the node has already is a 409, disabled on a tethered node (e.g. a page\'s main collection) a 422. Whether the account may change the node is up to its workspace roles and node privileges (else a 403), as in the Neos backend. The response is the node as getNode returns it without include.',
        operationId: 'tagNode',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::NODES_UPDATE],
        ],
    )]
    public function tag(
        ContentRepositoryId $contentRepositoryId,
        NodeAggregateId $nodeAggregateId,
        #[Parameter(in: 'query', description: 'The workspace to tag the node in, required: changing live directly is rarely what is meant')]
        WorkspaceName $workspaceName,
        #[RequestBody(description: 'The tag')] NodeTag $newTag,
        #[Parameter(in: 'query', description: 'The dimension space point to tag the node in (and the points that fall back to it), as JSON. If omitted, as for getNode')]
        DimensionSpacePoint|null $dimensionSpacePoint = null,
    ): Node|NotFound|BadRequest|Forbidden|Conflict|UnprocessableContent {
        $tag = $newTag->tag->toSubtreeTag();
        if ($tag->equals(NeosSubtreeTag::removed())) {
            return UnprocessableContent::because('Nodes can\'t be tagged removed, deleteNode removes them');
        }
        $node = $this->nodeIn($contentRepositoryId, $nodeAggregateId, $workspaceName, $dimensionSpacePoint);
        if (!$node instanceof ContentGraph\Node) {
            return $node;
        }
        try {
            $this->contentRepositoryRegistry->get($node->contentRepositoryId)->handle(TagSubtree::create($node->workspaceName, $node->aggregateId, $node->dimensionSpacePoint, NodeVariantSelectionStrategy::STRATEGY_ALL_SPECIALIZATIONS, $tag));
        } catch (AccessDenied) {
            return Forbidden::because(sprintf('You may not change the node %s in the workspace %s', $node->aggregateId->value, $node->workspaceName->value));
        } catch (SubtreeIsAlreadyTagged) {
            return Conflict::because(sprintf('The node %s is tagged %s already', $node->aggregateId->value, $tag->value));
        } catch (NodeAggregateIsTethered) {
            return UnprocessableContent::because(sprintf('The node %s is tethered to its parent, it can\'t be tagged %s', $node->aggregateId->value, $tag->value));
        }
        return $this->reread($node);
    }

    #[Operation(
        path: '/cr/{contentRepositoryId}/nodes/{nodeAggregateId}/tags/{tag}',
        method: 'DELETE',
        summary: 'Untag a node',
        description: 'Removes the tag from the node and everything below it in the workspace and dimension space point and the points that fall back to it (UntagSubtree), as the Neos backend does: untagging disabled shows it again. A tag the node doesn\'t have itself is a 404, also one it only inherits from an ancestor (inheritedTags), untag that one instead. removed is a 422: removed nodes aren\'t found, restoreNode restores them. Whether the account may change the node is up to its workspace roles and node privileges (else a 403), as in the Neos backend. The response is the node as getNode returns it without include.',
        operationId: 'untagNode',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::NODES_UPDATE],
        ],
    )]
    public function untag(
        ContentRepositoryId $contentRepositoryId,
        NodeAggregateId $nodeAggregateId,
        SubtreeTag $tag,
        #[Parameter(in: 'query', description: 'The workspace to untag the node in, required: changing live directly is rarely what is meant')]
        WorkspaceName $workspaceName,
        #[Parameter(in: 'query', description: 'The dimension space point to untag the node in (and the points that fall back to it), as JSON. If omitted, as for getNode')]
        DimensionSpacePoint|null $dimensionSpacePoint = null,
    ): Node|NotFound|BadRequest|Forbidden|UnprocessableContent {
        // removed nodes aren't found, restoring them is up to restore()
        if ($tag->toSubtreeTag()->equals(NeosSubtreeTag::removed())) {
            return UnprocessableContent::because('removed can\'t be untagged, restoreNode restores removed nodes');
        }
        $node = $this->nodeIn($contentRepositoryId, $nodeAggregateId, $workspaceName, $dimensionSpacePoint);
        if (!$node instanceof ContentGraph\Node) {
            return $node;
        }
        try {
            $this->contentRepositoryRegistry->get($node->contentRepositoryId)->handle(UntagSubtree::create($node->workspaceName, $node->aggregateId, $node->dimensionSpacePoint, NodeVariantSelectionStrategy::STRATEGY_ALL_SPECIALIZATIONS, $tag->toSubtreeTag()));
        } catch (AccessDenied) {
            return Forbidden::because(sprintf('You may not change the node %s in the workspace %s', $node->aggregateId->value, $node->workspaceName->value));
        } catch (SubtreeIsNotTagged) {
            return NotFound::because(sprintf('The node %s isn\'t tagged %s itself', $node->aggregateId->value, $tag->value));
        }
        return $this->reread($node);
    }

    #[Operation(
        path: '/cr/{contentRepositoryId}/nodes/{nodeAggregateId}/restore',
        method: 'POST',
        summary: 'Restore a deleted node',
        description: 'Undoes deleteNode as Neos\' trash does: the node is restored in all dimension space points it was deleted in, with everything below it, and so are its deleted ancestors, so it has its place in the tree again. Only while the deletion is soft, until it is published and the grace period (Neos.Neos.softRemoval.garbageCollectionGracePeriod) is over, afterwards the node is gone, a 404 as an unknown one. Before publishing, discarding the workspace\'s changes undoes the deletion as well. A node that isn\'t deleted itself (also one inside a deleted node, restore that one) is a 409, as is a workspace that is outdated (rebase it first). Whether the account may restore the node is up to its workspace roles and node privileges (else a 403), as in the Neos backend.',
        operationId: 'restoreNode',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::NODES_DELETE],
        ],
    )]
    public function restore(
        ContentRepositoryId $contentRepositoryId,
        NodeAggregateId $nodeAggregateId,
        #[Parameter(in: 'query', description: 'The workspace to restore the node in, required: changing live directly is rarely what is meant')]
        WorkspaceName $workspaceName,
        #[AuthContext] ApiCaller $caller,
    ): NotFound|Forbidden|Conflict|null {
        // removed nodes aren't in any subgraph, they are found in the content graph, as in Neos' RestoreController
        $workspace = $this->workspaceResolver->resolve($contentRepositoryId, $workspaceName, $caller->account);
        if (!$workspace instanceof ReadableWorkspace) {
            return $workspace;
        }
        $id = $nodeAggregateId->toNodeAggregateId();
        $contentGraph = $workspace->contentRepository->getContentGraph($workspace->workspace->workspaceName);
        $nodeAggregate = $contentGraph->findNodeAggregateById($id);
        if ($nodeAggregate === null || !$workspace->mayReadNodeAggregate($id)) {
            return NotFound::because(sprintf('There is no node %s in the workspace %s', $id->value, $workspace->workspace->workspaceName->value));
        }
        $removedIn = $nodeAggregate->getCoveredDimensionsTaggedBy(NeosSubtreeTag::removed(), withoutInherited: true);
        if ($removedIn->isEmpty()) {
            return Conflict::because(sprintf('The node %s isn\'t deleted itself in the workspace %s', $id->value, $workspace->workspace->workspaceName->value));
        }
        if ($workspace->workspace->status !== SharedModel\Workspace\WorkspaceStatus::UP_TO_DATE) {
            return Conflict::because(sprintf('The workspace %s is outdated, rebase it first', $workspace->workspace->workspaceName->value));
        }
        try {
            foreach ([$nodeAggregate, ...$this->removedAncestors($id, $contentGraph)] as $removed) {
                $points = $removed->getCoveredDimensionsTaggedBy(NeosSubtreeTag::removed(), withoutInherited: true)->points;
                $workspace->contentRepository->handle(UntagSubtree::create(
                    $workspace->workspace->workspaceName,
                    $removed->nodeAggregateId,
                    reset($points) ?: throw new \LogicException('A removed node aggregate is removed somewhere', 1791449421),
                    NodeVariantSelectionStrategy::STRATEGY_ALL_VARIANTS,
                    NeosSubtreeTag::removed(),
                ));
            }
        } catch (AccessDenied) {
            return Forbidden::because(sprintf('You may not restore the node %s in the workspace %s', $id->value, $workspace->workspace->workspaceName->value));
        }
        return null;
    }

    /**
     * The ancestors that are removed themselves, in any dimension space point, nearest first, as Neos' trash restores
     * them with the node (RestoreController::gatherAdditionalAncestorsThatWillBeRestored())
     *
     * @return list<NodeAggregate>
     */
    private function removedAncestors(SharedModel\Node\NodeAggregateId $id, ContentGraphInterface $contentGraph): array
    {
        $removed = [];
        foreach ($contentGraph->findParentNodeAggregates($id) as $parent) {
            if (!$parent->getCoveredDimensionsTaggedBy(NeosSubtreeTag::removed(), withoutInherited: true)->isEmpty()) {
                $removed[$parent->nodeAggregateId->value] = $parent;
            }
            // a node may have other parents in other dimension space points, their ancestors may meet
            foreach ($this->removedAncestors($parent->nodeAggregateId, $contentGraph) as $ancestor) {
                $removed[$ancestor->nodeAggregateId->value] ??= $ancestor;
            }
        }
        return array_values($removed);
    }

    #[Operation(
        path: '/cr/{contentRepositoryId}/nodes/{nodeAggregateId}/nodetype',
        method: 'PUT',
        summary: 'Change the node type of a node',
        description: 'Changes the node type of the node in the workspace, in all of its dimension space points (ChangeNodeAggregateType), as the Neos backend does: properties the new node type doesn\'t declare are removed, its defaults are set for the ones the node doesn\'t have, its tethered child nodes are created, and child nodes it doesn\'t allow are deleted as with deleteNode (soft, restoreNode restores them). The same node type changes nothing. An unknown, abstract or root node type, one the parent doesn\'t allow, or a root or tethered node (e.g. a page\'s main collection) are a 422, a child node with the name of one of the new type\'s tethered child nodes a 409. Whether the account may change the node is up to its workspace roles and node privileges (else a 403), as in the Neos backend. The response is the node in the dimension space point as getNode returns it without include.',
        operationId: 'changeNodeType',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::NODES_UPDATE],
        ],
    )]
    public function changeNodeType(
        ContentRepositoryId $contentRepositoryId,
        NodeAggregateId $nodeAggregateId,
        #[Parameter(in: 'query', description: 'The workspace to change the node in, required: changing live directly is rarely what is meant')]
        WorkspaceName $workspaceName,
        #[RequestBody(description: 'The new node type')] NodeTypeChange $change,
        #[Parameter(in: 'query', description: 'The dimension space point to read the node in, the change is to all of them, as JSON. If omitted, as for getNode')]
        DimensionSpacePoint|null $dimensionSpacePoint = null,
    ): Node|NotFound|BadRequest|Forbidden|Conflict|UnprocessableContent {
        $node = $this->nodeIn($contentRepositoryId, $nodeAggregateId, $workspaceName, $dimensionSpacePoint);
        if (!$node instanceof ContentGraph\Node) {
            return $node;
        }
        $contentRepository = $this->contentRepositoryRegistry->get($node->contentRepositoryId);
        $nodeTypeName = NodeType\NodeTypeName::fromString($change->nodeType->value);
        if ($contentRepository->getNodeTypeManager()->getNodeType($nodeTypeName) === null) {
            return UnprocessableContent::because(sprintf('There is no node type %s', $nodeTypeName->value));
        }
        if (!$nodeTypeName->equals($node->nodeTypeName)) {
            try {
                // as Neos UI, which soft removes the child nodes the new node type doesn't allow, see NeosSubtreeTag::removed()
                $contentRepository->handle(ChangeNodeAggregateType::create(
                    $node->workspaceName,
                    $node->aggregateId,
                    $nodeTypeName,
                    new NodeAggregateTypeChangeChildConstraintConflictResolutionMarkWithTagStrategy(NeosSubtreeTag::removed()),
                ));
            } catch (AccessDenied) {
                return Forbidden::because(sprintf('You may not change the node %s in the workspace %s', $node->aggregateId->value, $node->workspaceName->value));
            } catch (NodeAggregateIsUntethered $exception) {
                return Conflict::because($exception->getMessage());
            } catch (NodeAggregateIsRoot | NodeAggregateIsTethered | NodeTypeIsAbstract | NodeTypeIsOfTypeRoot | NodeConstraintException $exception) {
                return UnprocessableContent::because($exception->getMessage());
            }
        }
        return $this->reread($node);
    }

    #[Operation(
        path: '/cr/{contentRepositoryId}/nodes/{nodeAggregateId}/move',
        method: 'POST',
        summary: 'Move a node',
        description: 'Moves the node with everything below it in the workspace (MoveNodeAggregate), as the Neos backend does: below parentNodeAggregateId as its last child, or before succeedingSiblingNodeAggregateId (below its parent, or below parentNodeAggregateId if given, of which it must be a child). In other dimension space points the node type\'s options.moveNodeStrategy decides: gatherAll (Neos\' default for documents, and if there is none) moves all of its variants, scatter (for content) only the one in this very point, not even in the points that fall back to it (e.g. en_UK showing en_US), gatherSpecializations the ones in this point and its specializations. Neither parent nor sibling is a 400, an unknown one a 404. A root or tethered node, a parent that doesn\'t allow the node, one inside the node itself, or a sibling that isn\'t a child of the parent are a 422, a parent that isn\'t in all the points to move in or has a child of the same name a 409. Whether the account may move the node is up to its workspace roles and node privileges (else a 403), as in the Neos backend. The response is the node as getNode returns it without include.',
        operationId: 'moveNode',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::NODES_UPDATE],
        ],
    )]
    public function move(
        ContentRepositoryId $contentRepositoryId,
        NodeAggregateId $nodeAggregateId,
        #[Parameter(in: 'query', description: 'The workspace to move the node in, required: changing live directly is rarely what is meant')]
        WorkspaceName $workspaceName,
        #[RequestBody(description: 'Where to move the node')] NodeMove $target,
        #[Parameter(in: 'query', description: 'The dimension space point to move the node in, the others as its moveNodeStrategy says, as JSON. If omitted, as for getNode')]
        DimensionSpacePoint|null $dimensionSpacePoint = null,
    ): Node|NotFound|BadRequest|Forbidden|Conflict|UnprocessableContent {
        if ($target->parentNodeAggregateId === null && $target->succeedingSiblingNodeAggregateId === null) {
            return BadRequest::because('Give parentNodeAggregateId or succeedingSiblingNodeAggregateId, or both');
        }
        $node = $this->nodeIn($contentRepositoryId, $nodeAggregateId, $workspaceName, $dimensionSpacePoint);
        if (!$node instanceof ContentGraph\Node) {
            return $node;
        }
        if ($target->succeedingSiblingNodeAggregateId?->value === $node->aggregateId->value) {
            return UnprocessableContent::because('A node can\'t be moved before itself');
        }
        $subgraph = $this->contentRepositoryRegistry->subgraphForNode($node);
        $sibling = $target->succeedingSiblingNodeAggregateId !== null ? $this->findNode($subgraph, $target->succeedingSiblingNodeAggregateId) : null;
        if ($sibling instanceof NotFound) {
            return $sibling;
        }
        $parent = $target->parentNodeAggregateId !== null ? $this->findNode($subgraph, $target->parentNodeAggregateId) : $subgraph->findParentNode($sibling->aggregateId);
        if ($parent instanceof NotFound) {
            return $parent;
        }
        if ($parent === null) {
            return UnprocessableContent::because(sprintf('The node %s is a root node, nodes can\'t be moved before it', $sibling->aggregateId->value));
        }
        $contentRepository = $this->contentRepositoryRegistry->get($node->contentRepositoryId);
        $strategy = $this->moveNodeStrategy($contentRepository->getNodeTypeManager()->getNodeType($node->nodeTypeName));
        try {
            // as Neos UI: the parent only if it changes, and the other neighbour, which the content repository uses in points the sibling isn't in
            $contentRepository->handle(MoveNodeAggregate::create(
                $node->workspaceName,
                $node->dimensionSpacePoint,
                $node->aggregateId,
                $strategy,
                $subgraph->findParentNode($node->aggregateId)?->aggregateId->equals($parent->aggregateId) === true ? null : $parent->aggregateId,
                $this->precedingSibling($subgraph, $parent, $node, $sibling),
                $sibling?->aggregateId,
            ));
        } catch (AccessDenied) {
            return Forbidden::because(sprintf('You may not move the node %s in the workspace %s', $node->aggregateId->value, $node->workspaceName->value));
        } catch (NodeAggregateDoesCurrentlyNotCoverDimensionSpacePointSet | NodeNameIsAlreadyCovered $exception) {
            return Conflict::because($exception->getMessage());
        } catch (NodeAggregateIsRoot | NodeAggregateIsTethered | NodeConstraintException | NodeAggregateIsDescendant | NodeAggregateIsNoChild | NodeAggregateIsNoSibling $exception) {
            return UnprocessableContent::because($exception->getMessage());
        }
        return $this->reread($node);
    }

    /**
     * How the node's other variants move along, as Neos UI: the node type's options.moveNodeStrategy, which Neos UI's
     * node types configure (gatherAll for documents, scatter for content), gatherAll without one, the safe choice
     */
    private function moveNodeStrategy(?NodeType\NodeType $nodeType): RelationDistributionStrategy
    {
        $configured = $nodeType?->getConfiguration('options.moveNodeStrategy');
        if ($configured === null) {
            return RelationDistributionStrategy::STRATEGY_GATHER_ALL;
        }
        return is_string($configured) && ($strategy = RelationDistributionStrategy::tryFrom($configured)) !== null
            ? $strategy
            : throw new \RuntimeException(sprintf('The node type %s has an invalid options.moveNodeStrategy %s', $nodeType?->name->value, json_encode($configured)), 1791462318);
    }

    /**
     * The child of the parent before the sibling, or its last child without one, leaving out the node itself: where the
     * node goes in points the sibling isn't in. Null if there is none, also if the sibling isn't a child of the parent
     */
    private function precedingSibling(ContentSubgraphInterface $subgraph, ContentGraph\Node $parent, ContentGraph\Node $node, ?ContentGraph\Node $sibling): ?SharedModel\Node\NodeAggregateId
    {
        $preceding = null;
        foreach ($subgraph->findChildNodes($parent->aggregateId, FindChildNodesFilter::create()) as $child) {
            if ($sibling !== null && $child->aggregateId->equals($sibling->aggregateId)) {
                return $preceding;
            }
            if (!$child->aggregateId->equals($node->aggregateId)) {
                $preceding = $child->aggregateId;
            }
        }
        return $sibling === null ? $preceding : null;
    }

    /**
     * The node in the subgraph of the request, see subgraph() and findNode()
     */
    private function nodeIn(ContentRepositoryId $contentRepositoryId, NodeAggregateId $nodeAggregateId, ?WorkspaceName $workspaceName, ?DimensionSpacePoint $dimensionSpacePoint): ContentGraph\Node|NotFound|BadRequest
    {
        $subgraph = $this->subgraph($contentRepositoryId, $workspaceName, $dimensionSpacePoint);
        return $subgraph instanceof ContentSubgraphInterface ? $this->findNode($subgraph, $nodeAggregateId) : $subgraph;
    }

    /**
     * The node after a change, nodes are read-only
     */
    private function reread(ContentGraph\Node $node): Node|NotFound
    {
        $changed = $this->findNode($this->contentRepositoryRegistry->subgraphForNode($node), NodeAggregateId::fromString($node->aggregateId->value));
        return $changed instanceof ContentGraph\Node ? $this->node($changed, IncludePaths::none()) : $changed;
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
