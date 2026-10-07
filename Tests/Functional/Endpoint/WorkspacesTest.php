<?php
declare(strict_types=1);

namespace Neos\Api\Tests\Functional\Endpoint;

use Neos\Neos\Domain\Service\UserService;
use PHPUnit\Framework\Attributes\Test;

/**
 * Workspaces and their events need a content repository, which the Testing context (SQLite) doesn't have: these tests cover everything
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
        $response = $this->get('/api/cr/unknown/workspaces', $this->token('nobody-machine', 'workspaces.read'));

        self::assertSame(404, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('There is no content repository with the ID unknown', self::json($response)['detail']);
    }

    #[Test]
    public function rejectsInvalidContentRepositoryIds(): void
    {
        self::assertSame(400, $this->get('/api/cr/Not-An-Id/workspaces', $this->token('nobody-machine', 'workspaces.read'))->getStatusCode());
    }

    #[Test]
    public function documentsTheOperationUnderItsOwnTag(): void
    {
        $operation = self::json($this->get('/api/openapi.json', null))['paths']['/cr/{contentRepositoryId}/workspaces']['get'];
        self::assertSame('listWorkspaces', $operation['operationId']);
        self::assertSame(['Workspaces'], $operation['tags']);
    }

    #[Test]
    public function requiresTheScope(): void
    {
        $response = $this->get('/api/cr/default/workspaces', $this->token('nobody-machine', 'nodes.read'));

        self::assertSame(403, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringContainsString('workspaces.read', self::json($response)['detail']);
    }

    #[Test]
    public function requiresAToken(): void
    {
        self::assertSame(401, $this->get('/api/cr/default/workspaces', null)->getStatusCode());
    }

    #[Test]
    public function eventsOfUnknownContentRepositoriesAreNotFound(): void
    {
        // every account may list events, which ones is up to its workspace roles and node privileges
        $response = $this->get('/api/cr/unknown/workspaces/live/events', $this->token('nobody-machine', 'workspaces.read'));

        self::assertSame(404, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('There is no content repository with the ID unknown', self::json($response)['detail']);
    }

    #[Test]
    public function rejectsInvalidEventParameters(): void
    {
        $token = $this->token('nobody-machine', 'workspaces.read');

        self::assertSame(400, $this->get('/api/cr/default/workspaces/Not-A-Name/events', $token)->getStatusCode());
        self::assertSame(400, $this->get('/api/cr/default/workspaces/live/events?after=0', $token)->getStatusCode());
        self::assertSame(400, $this->get('/api/cr/default/workspaces/live/events?limit=101', $token)->getStatusCode());
    }

    #[Test]
    public function documentsTheEventsUnderTheWorkspacesTag(): void
    {
        $operation = self::json($this->get('/api/openapi.json', null))['paths']['/cr/{contentRepositoryId}/workspaces/{workspaceName}/events']['get'];
        self::assertSame('listWorkspaceEvents', $operation['operationId']);
        self::assertSame(['Workspaces'], $operation['tags']);
        self::assertSame(['contentRepositoryId', 'workspaceName', 'after', 'limit'], array_column($operation['parameters'], 'name'));
    }

    #[Test]
    public function eventsRequireTheWorkspacesScope(): void
    {
        $response = $this->get('/api/cr/default/workspaces/live/events', $this->token('nobody-machine', 'nodes.read'));

        self::assertSame(403, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringContainsString('workspaces.read', self::json($response)['detail']);
    }

    #[Test]
    public function eventsRequireAToken(): void
    {
        self::assertSame(401, $this->get('/api/cr/default/workspaces/live/events', null)->getStatusCode());
    }
}
