<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\NodeTypes;

use Neos\Api\Endpoint\ContentRepositories\Schema\ContentRepositoryId;
use Neos\Api\Endpoint\NodeTypes\Schema\NodeType;
use Neos\Api\Endpoint\NodeTypes\Schema\NodeTypeName;
use Neos\Api\Endpoint\NodeTypes\Schema\NodeTypeList;
use Neos\Api\Infrastructure\I18n\LabelTranslator;
use Neos\Api\Security\ApiAuthContextProvider;
use Neos\Api\Security\ApiScopes;
use Neos\Api\Shared\Params;
use Neos\Api\Shared\Response\BadRequest;
use Neos\Api\Shared\Response\NotFound;
use Neos\Api\Shared\Schema\AcceptLanguage;
use Neos\ContentRepository\Core;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
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
        private ContentRepositoryRegistry $contentRepositoryRegistry,
        private LabelTranslator $labelTranslator,
        private IconNameMappingService $iconNameMappingService,
    ) {
    }

    #[Operation(
        path: '/nodetypes',
        method: 'GET',
        summary: 'List the node types',
        description: 'All node types of a content repository, abstract ones included, sorted by name, or with superType only the ones of that type. Their properties, references and configuration are null unless included, getNodeType has them all. The labels are translated to the Accept-Language.',
        operationId: 'listNodeTypes',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::NODETYPES_READ],
        ],
    )]
    public function list(
        #[Parameter(in: 'query', description: 'The content repository, default if omitted')]
        ContentRepositoryId|null $contentRepositoryId = null,
        #[Parameter(in: 'query', description: 'Only the node types of this type: it and the ones inheriting from it, directly or not')]
        NodeTypeName|null $superType = null,
        #[Parameter(in: 'query', description: 'What to include beyond the node types\' own fields, comma-separated: properties, references, configuration')]
        Params\IncludePaths|null $include = null,
        #[Parameter(in: 'header', name: 'Accept-Language')] AcceptLanguage|null $acceptLanguage = null,
    ): NodeTypeList|NotFound|BadRequest {
        $includePaths = $include?->paths() ?? [];
        $unknown = array_diff($includePaths, self::INCLUDE_PATHS);
        if ($unknown !== []) {
            return BadRequest::because(sprintf('Can\'t include %s, only: %s', implode(', ', $unknown), implode(', ', self::INCLUDE_PATHS)));
        }
        $nodeTypeManager = $this->nodeTypeManager($contentRepositoryId);
        if ($nodeTypeManager === null) {
            return $this->contentRepositoryNotFound($contentRepositoryId);
        }
        if ($superType !== null && !$nodeTypeManager->hasNodeType($superType->value)) {
            return BadRequest::because(sprintf('There is no node type %s', $superType->value));
        }
        $labels = $this->labelTranslator->forAcceptLanguage($acceptLanguage);
        $nodeTypes = $nodeTypeManager->getNodeTypes();
        if ($superType !== null) {
            $nodeTypes = array_filter($nodeTypes, static fn (Core\NodeType\NodeType $nodeType) => $nodeType->isOfType($superType->value));
        }
        ksort($nodeTypes);
        return new NodeTypeList(...array_map(
            fn (Core\NodeType\NodeType $nodeType) => NodeType::from(
                $nodeType,
                $labels,
                $this->iconNameMappingService,
                withProperties: in_array('properties', $includePaths, true),
                withReferences: in_array('references', $includePaths, true),
                withConfiguration: in_array('configuration', $includePaths, true),
            ),
            array_values($nodeTypes),
        ));
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
        $value = $contentRepositoryId->value ?? self::DEFAULT_CONTENT_REPOSITORY_ID;
        // checked up front: the registry throws for an unknown one
        foreach ($this->contentRepositoryRegistry->getContentRepositoryIds() as $id) {
            if ($id->value === $value) {
                return $this->contentRepositoryRegistry->get($id)->getNodeTypeManager();
            }
        }
        return null;
    }

    private function contentRepositoryNotFound(ContentRepositoryId|null $contentRepositoryId): NotFound
    {
        return NotFound::because(sprintf('There is no content repository with the ID %s', $contentRepositoryId->value ?? self::DEFAULT_CONTENT_REPOSITORY_ID));
    }
}
