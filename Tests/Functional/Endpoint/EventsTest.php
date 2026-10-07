<?php
declare(strict_types=1);

namespace Neos\Api\Tests\Functional\Endpoint;

use Neos\Neos\Domain\Service\UserService;
use PHPUnit\Framework\Attributes\Test;

/**
 * Events need a content repository, which the Testing context (SQLite) doesn't have: these tests cover everything
 * up to the content repository lookup
 */
class EventsTest extends EndpointTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->objectManager->get(UserService::class)->createUser('nobody', 'password', 'Nora', 'Nobody', []);
        $this->addMachineClient('nobody-machine', 'nobody');
        $this->persistenceManager->persistAll();
    }

    #[Test]
    public function eventsOfUnknownContentRepositoriesAreNotFound(): void
    {
        // every account may list events, which ones is up to its workspace roles and node privileges
        $response = $this->get('/api/cr/unknown/workspaces/live/events', $this->token('nobody-machine', 'events.read'));

        self::assertSame(404, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('There is no content repository with the ID unknown', self::json($response)['detail']);
    }

    #[Test]
    public function rejectsInvalidParameters(): void
    {
        $token = $this->token('nobody-machine', 'events.read');

        self::assertSame(400, $this->get('/api/cr/default/workspaces/Not-A-Name/events', $token)->getStatusCode());
        self::assertSame(400, $this->get('/api/cr/default/workspaces/live/events?after=0', $token)->getStatusCode());
        self::assertSame(400, $this->get('/api/cr/default/workspaces/live/events?limit=101', $token)->getStatusCode());
    }

    #[Test]
    public function documentsTheOperationUnderItsOwnTag(): void
    {
        $operation = self::json($this->get('/api/openapi.json', null))['paths']['/cr/{contentRepositoryId}/workspaces/{workspaceName}/events']['get'];
        self::assertSame('listWorkspaceEvents', $operation['operationId']);
        self::assertSame(['Events'], $operation['tags']);
        self::assertSame(['contentRepositoryId', 'workspaceName', 'after', 'limit'], array_column($operation['parameters'], 'name'));
    }

    #[Test]
    public function requiresTheScope(): void
    {
        $response = $this->get('/api/cr/default/workspaces/live/events', $this->token('nobody-machine', 'workspaces.read'));

        self::assertSame(403, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringContainsString('events.read', self::json($response)['detail']);
    }

    #[Test]
    public function requiresAToken(): void
    {
        self::assertSame(401, $this->get('/api/cr/default/workspaces/live/events', null)->getStatusCode());
    }
}
