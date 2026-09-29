<?php
declare(strict_types=1);

namespace Neos\Api\Endpoints;

use Neos\Api\Domain\ContentRepository\ContentRepositories as ContentRepositoryList;
use Neos\Api\Domain\ContentRepository\ContentRepository;
use Neos\Api\Domain\ContentRepository\ContentRepositoryId;
use Neos\Api\Endpoints\Model\AcceptLanguage;
use Neos\Api\Endpoints\Response\NotFound;
use Neos\Api\Endpoints\Response\TranslatedContentRepositories;
use Neos\Api\Endpoints\Response\TranslatedContentRepository;
use Neos\Api\I18n\LabelTranslator;
use Neos\Api\I18n\Labels;
use Neos\Api\Security\ApiAuthContextProvider;
use Neos\Api\Security\ApiScopes;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId as NeosContentRepositoryId;
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
    ): TranslatedContentRepositories {
        $labels = $this->labelTranslator->forAcceptLanguage($acceptLanguage);
        return new TranslatedContentRepositories(new ContentRepositoryList(...array_map(
            fn (NeosContentRepositoryId $id) => $this->contentRepository($id, $labels),
            iterator_to_array($this->contentRepositoryRegistry->getContentRepositoryIds(), false),
        )), $labels);
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
    ): TranslatedContentRepository|NotFound {
        // checked up front: the registry throws for an unknown one
        foreach ($this->contentRepositoryRegistry->getContentRepositoryIds() as $id) {
            if ($id->value === $contentRepositoryId->value) {
                $labels = $this->labelTranslator->forAcceptLanguage($acceptLanguage);
                return new TranslatedContentRepository($this->contentRepository($id, $labels), $labels);
            }
        }
        return NotFound::because(sprintf('There is no content repository with the ID %s', $contentRepositoryId->value));
    }

    private function contentRepository(NeosContentRepositoryId $contentRepositoryId, Labels $labels): ContentRepository
    {
        return ContentRepository::fromNeosContentRepository($this->contentRepositoryRegistry->get($contentRepositoryId), $labels);
    }
}
