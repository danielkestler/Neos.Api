<?php
declare(strict_types=1);

namespace Neos\Api\Endpoints;

use Neos\Api\Domain\ContentRepository\ContentRepositories as ContentRepositoryList;
use Neos\Api\Domain\ContentRepository\ContentRepository;
use Neos\Api\Domain\ContentRepository\ContentRepositoryId;
use Neos\Api\Endpoints\Response\NotFound;
use Neos\Api\Security\ApiAuthContextProvider;
use Neos\Api\Security\ApiScopes;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId as NeosContentRepositoryId;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\OpenApi\Attributes\Operation;

/**
 * The content repositories of the Neos.ContentRepositoryRegistry
 */
final readonly class ContentRepositories
{
    public function __construct(
        private ContentRepositoryRegistry $contentRepositoryRegistry,
    ) {
    }

    #[Operation(
        path: '/contentrepositories',
        method: 'GET',
        summary: 'List all content repositories',
        description: 'The content repositories configured in the Neos.ContentRepositoryRegistry settings, in the order of the settings.',
        operationId: 'listContentRepositories',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::CONTENTREPOSITORIES_READ],
        ],
    )]
    public function list(): ContentRepositoryList
    {
        return new ContentRepositoryList(...array_map(
            $this->contentRepository(...),
            iterator_to_array($this->contentRepositoryRegistry->getContentRepositoryIds(), false),
        ));
    }

    #[Operation(
        path: '/contentrepositories/{contentRepositoryId}',
        method: 'GET',
        summary: 'Get a content repository',
        operationId: 'getContentRepository',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::CONTENTREPOSITORIES_READ],
        ],
    )]
    public function get(ContentRepositoryId $contentRepositoryId): ContentRepository|NotFound
    {
        // checked up front: the registry throws for an unknown one
        foreach ($this->contentRepositoryRegistry->getContentRepositoryIds() as $id) {
            if ($id->value === $contentRepositoryId->value) {
                return $this->contentRepository($id);
            }
        }
        return NotFound::because(sprintf('There is no content repository with the ID %s', $contentRepositoryId->value));
    }

    private function contentRepository(NeosContentRepositoryId $contentRepositoryId): ContentRepository
    {
        return ContentRepository::fromNeosContentRepository($this->contentRepositoryRegistry->get($contentRepositoryId));
    }
}
