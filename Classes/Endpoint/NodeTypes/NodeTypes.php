<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\NodeTypes;

use Neos\Api\Endpoint\ContentRepositories\Schema\ContentRepositoryId;
use Neos\Api\Endpoint\NodeTypes\Params\NodeTypeFilter;
use Neos\Api\Endpoint\NodeTypes\Schema\NodeType;
use Neos\Api\Endpoint\NodeTypes\Schema\NodeTypeName;
use Neos\Api\Endpoint\NodeTypes\Schema\NodeTypeList;
use Neos\Api\Endpoint\NodeTypes\Schema\NodeTypeListing;
use Neos\Api\Infrastructure\ContentRepository\ContentRepositoryFinder;
use Neos\Api\Infrastructure\I18n\LabelTranslator;
use Neos\Api\Security\ApiAuthContextProvider;
use Neos\Api\Security\ApiScopes;
use Neos\Api\Shared\Params;
use Neos\Api\Shared\Response\BadRequest;
use Neos\Api\Shared\Response\NotFound;
use Neos\Api\Shared\Schema\AcceptLanguage;
use Neos\ContentRepository\Core;
use Neos\Neos\Service\IconNameMappingService;
use Neos\OpenApi\Attributes\Operation;
use Neos\OpenApi\Attributes\Parameter;

/**
 * The node types of the content repositories, the content model generic clients like form generators work with
 */
final readonly class NodeTypes
{
    private const string DEFAULT_CONTENT_REPOSITORY_ID = 'default';

    private const array INCLUDE_PATHS = ['properties', 'references', 'configuration'];

    public function __construct(
        private ContentRepositoryFinder $contentRepositoryFinder,
        private LabelTranslator $labelTranslator,
        private IconNameMappingService $iconNameMappingService,
    ) {
    }

    #[Operation(
        path: '/nodetypes',
        method: 'GET',
        summary: 'List the node types',
        description: 'All node types of a content repository, abstract ones included, sorted by name, or with filter[superType] only the ones of that type. Their properties, references and configuration are null unless included, getNodeType has them all. The labels are translated to the Accept-Language.',
        operationId: 'listNodeTypes',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::NODETYPES_READ],
        ],
    )]
    public function list(
        #[Parameter(in: 'query', description: 'The content repository, default if omitted')]
        ContentRepositoryId|null $contentRepositoryId = null,
        #[Parameter(in: 'query', description: 'Which node types: with filter[superType] only the ones of this type, it and the ones inheriting from it, directly or not')]
        NodeTypeFilter|null $filter = null,
        #[Parameter(in: 'query', description: 'What to include beyond the node types\' own fields, comma-separated: properties, references, configuration')]
        Params\IncludePaths|null $include = null,
        #[Parameter(in: 'header', name: 'Accept-Language')] AcceptLanguage|null $acceptLanguage = null,
    ): NodeTypeListing|NotFound|BadRequest {
        $include ??= Params\IncludePaths::none();
        $unsupported = $include->unsupported(self::INCLUDE_PATHS);
        if ($unsupported !== null) {
            return $unsupported;
        }
        $nodeTypeManager = $this->nodeTypeManager($contentRepositoryId);
        if ($nodeTypeManager === null) {
            return $this->contentRepositoryNotFound($contentRepositoryId);
        }
        $superType = $filter?->superType;
        if ($superType !== null && !$nodeTypeManager->hasNodeType($superType->value)) {
            return BadRequest::because(sprintf('There is no node type %s', $superType->value));
        }
        $labels = $this->labelTranslator->forAcceptLanguage($acceptLanguage);
        $nodeTypes = $nodeTypeManager->getNodeTypes();
        if ($superType !== null) {
            $nodeTypes = array_filter($nodeTypes, static fn (Core\NodeType\NodeType $nodeType) => $nodeType->isOfType($superType->value));
        }
        ksort($nodeTypes);
        return NodeTypeListing::of(new NodeTypeList(...array_map(
            fn (Core\NodeType\NodeType $nodeType) => NodeType::from(
                $nodeType,
                $labels,
                $this->iconNameMappingService,
                withProperties: $include->includes('properties'),
                withReferences: $include->includes('references'),
                withConfiguration: $include->includes('configuration'),
            ),
            array_values($nodeTypes),
        )));
    }

    #[Operation(
        path: '/nodetypes/{nodeTypeName}',
        method: 'GET',
        summary: 'Get a node type',
        description: 'A node type with its properties, references and whole configuration, merged with its super types\'. The labels are translated to the Accept-Language, the ones in the configuration as well.',
        operationId: 'getNodeType',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::NODETYPES_READ],
        ],
    )]
    public function get(
        NodeTypeName $nodeTypeName,
        #[Parameter(in: 'query', description: 'The content repository, default if omitted')]
        ContentRepositoryId|null $contentRepositoryId = null,
        #[Parameter(in: 'header', name: 'Accept-Language')] AcceptLanguage|null $acceptLanguage = null,
    ): NodeType|NotFound {
        $nodeTypeManager = $this->nodeTypeManager($contentRepositoryId);
        if ($nodeTypeManager === null) {
            return $this->contentRepositoryNotFound($contentRepositoryId);
        }
        $nodeType = $nodeTypeManager->getNodeType($nodeTypeName->value);
        if ($nodeType === null) {
            return NotFound::because(sprintf('There is no node type %s', $nodeTypeName->value));
        }
        return NodeType::from(
            $nodeType,
            $this->labelTranslator->forAcceptLanguage($acceptLanguage),
            $this->iconNameMappingService,
            withProperties: true,
            withReferences: true,
            withConfiguration: true,
        );
    }

    private function nodeTypeManager(ContentRepositoryId|null $contentRepositoryId): Core\NodeType\NodeTypeManager|null
    {
        $id = $contentRepositoryId?->toContentRepositoryId() ?? Core\SharedModel\ContentRepository\ContentRepositoryId::fromString(self::DEFAULT_CONTENT_REPOSITORY_ID);
        return $this->contentRepositoryFinder->find($id)?->getNodeTypeManager();
    }

    private function contentRepositoryNotFound(ContentRepositoryId|null $contentRepositoryId): NotFound
    {
        return NotFound::because(sprintf('There is no content repository with the ID %s', $contentRepositoryId->value ?? self::DEFAULT_CONTENT_REPOSITORY_ID));
    }
}
