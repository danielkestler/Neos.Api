<?php
declare(strict_types=1);

namespace Neos\Api\Tests\Functional\Endpoint;

use Neos\Neos\Domain\Service\UserService;
use PHPUnit\Framework\Attributes\Test;

/**
 * Workspaces need a content repository, which the Testing context (SQLite) doesn't have: these tests cover everything
 * up to the content repository lookup
 */
class WorkspacesTest extends EndpointTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->objectManager->get(UserService::class)->createUser('nobody', 'password', 'Nora', 'Nobody', []);
        $this->addMachineClient('nobody-machine', 'nobody');
        $this->persistenceManager->persistAll();
    }

    #[Test]
    public function workspacesOfUnknownContentRepositoriesAreNotFound(): void
    {
        // every account may list workspaces, which ones is up to its workspace roles
        $response = $this->get('/api/contentrepositories/unknown/workspaces', $this->token('nobody-machine', 'workspaces.read'));

        self::assertSame(404, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('There is no content repository with the ID unknown', self::json($response)['detail']);
    }

    #[Test]
    public function rejectsInvalidContentRepositoryIds(): void
    {
        self::assertSame(400, $this->get('/api/contentrepositories/Not-An-Id/workspaces', $this->token('nobody-machine', 'workspaces.read'))->getStatusCode());
    }

    #[Test]
    public function documentsTheOperationUnderItsOwnTag(): void
    {
        $operation = self::json($this->get('/api/openapi.json', null))['paths']['/contentrepositories/{contentRepositoryId}/workspaces']['get'];
        self::assertSame('listWorkspaces', $operation['operationId']);
        self::assertSame(['Workspaces'], $operation['tags']);
    }

    #[Test]
    public function requiresTheScope(): void
    {
        $response = $this->get('/api/contentrepositories/default/workspaces', $this->token('nobody-machine', 'nodes.read'));

        self::assertSame(403, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringContainsString('workspaces.read', self::json($response)['detail']);
    }

    #[Test]
    public function requiresAToken(): void
    {
        self::assertSame(401, $this->get('/api/contentrepositories/default/workspaces', null)->getStatusCode());
    }
}
