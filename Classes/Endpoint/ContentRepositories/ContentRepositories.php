<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\ContentRepositories;

use Neos\Api\Endpoint\ContentRepositories\Schema\ContentRepositories as ContentRepositoryList;
use Neos\Api\Endpoint\ContentRepositories\Schema\ContentRepository;
use Neos\Api\Endpoint\ContentRepositories\Schema\ContentRepositoryId;
use Neos\Api\Infrastructure\I18n\Labels;
use Neos\Api\Infrastructure\I18n\LabelTranslator;
use Neos\Api\Security\ApiAuthContextProvider;
use Neos\Api\Security\ApiScopes;
use Neos\Api\Shared\Response\NotFound;
use Neos\Api\Shared\Schema\AcceptLanguage;
use Neos\ContentRepository\Core\SharedModel;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\OpenApi\Attributes\Operation;
use Neos\OpenApi\Attributes\Parameter;

/**
 * The content repositories of the Neos.ContentRepositoryRegistry
 */
final readonly class ContentRepositories
{
    public function __construct(
        private ContentRepositoryRegistry $contentRepositoryRegistry,
        private LabelTranslator $labelTranslator,
    ) {
    }

    #[Operation(
        path: '/contentrepositories',
        method: 'GET',
        summary: 'List all content repositories',
        description: 'The content repositories configured in the Neos.ContentRepositoryRegistry settings, in the order of the settings. The labels are translated to the Accept-Language.',
        operationId: 'listContentRepositories',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::CONTENTREPOSITORIES_READ],
        ],
    )]
    public function list(
        #[Parameter(in: 'header', name: 'Accept-Language')] AcceptLanguage|null $acceptLanguage = null,
    ): ContentRepositoryList {
        $labels = $this->labelTranslator->forAcceptLanguage($acceptLanguage);
        return new ContentRepositoryList(...array_map(
            fn (SharedModel\ContentRepository\ContentRepositoryId $id) => $this->contentRepository($id, $labels),
            iterator_to_array($this->contentRepositoryRegistry->getContentRepositoryIds(), false),
        ));
    }

    #[Operation(
        path: '/contentrepositories/{contentRepositoryId}',
        method: 'GET',
        summary: 'Get a content repository',
        description: 'The labels are translated to the Accept-Language.',
        operationId: 'getContentRepository',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::CONTENTREPOSITORIES_READ],
        ],
    )]
    public function get(
        ContentRepositoryId $contentRepositoryId,
        #[Parameter(in: 'header', name: 'Accept-Language')] AcceptLanguage|null $acceptLanguage = null,
    ): ContentRepository|NotFound {
        // checked up front: the registry throws for an unknown one
        foreach ($this->contentRepositoryRegistry->getContentRepositoryIds() as $id) {
            if ($id->value === $contentRepositoryId->value) {
                return $this->contentRepository($id, $this->labelTranslator->forAcceptLanguage($acceptLanguage));
            }
        }
        return NotFound::because(sprintf('There is no content repository with the ID %s', $contentRepositoryId->value));
    }

    private function contentRepository(SharedModel\ContentRepository\ContentRepositoryId $contentRepositoryId, Labels $labels): ContentRepository
    {
        return ContentRepository::from($this->contentRepositoryRegistry->get($contentRepositoryId), $labels);
    }
}
