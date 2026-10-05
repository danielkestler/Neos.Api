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
use Neos\Api\Shared\Response\NotFound;
use Neos\Api\Shared\Schema\AcceptLanguage;
use Neos\ContentRepository\Core;
use Neos\ContentRepository\Core\SharedModel;
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
        description: 'All node types of a content repository, abstract ones included, sorted by name, without their configuration (see getNodeType). The labels are translated to the Accept-Language.',
        operationId: 'listNodeTypes',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::NODETYPES_READ],
        ],
    )]
    public function list(
        #[Parameter(in: 'query', description: 'The content repository, default if omitted')]
        ContentRepositoryId|null $contentRepositoryId = null,
        #[Parameter(in: 'header', name: 'Accept-Language')] AcceptLanguage|null $acceptLanguage = null,
    ): NodeTypeList|NotFound {
        $nodeTypeManager = $this->nodeTypeManager($contentRepositoryId);
        if ($nodeTypeManager === null) {
            return $this->contentRepositoryNotFound($contentRepositoryId);
        }
        $labels = $this->labelTranslator->forAcceptLanguage($acceptLanguage);
        $nodeTypes = $nodeTypeManager->getNodeTypes();
        ksort($nodeTypes);
        return new NodeTypeList(...array_map(
            fn (Core\NodeType\NodeType $nodeType) => NodeType::from($nodeType, $labels, $this->iconNameMappingService, withConfiguration: false),
            array_values($nodeTypes),
        ));
    }

    #[Operation(
        path: '/nodetypes/{nodeTypeName}',
        method: 'GET',
        summary: 'Get a node type',
        description: 'A node type with its whole configuration, merged with its super types\'. The label is translated to the Accept-Language, the labels in the configuration are not.',
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
        return NodeType::from($nodeType, $this->labelTranslator->forAcceptLanguage($acceptLanguage), $this->iconNameMappingService, withConfiguration: true);
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
