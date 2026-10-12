<?php
declare(strict_types=1);

namespace Neos\Api\Tests\Functional\Endpoint;

use Neos\Flow\Security\AccountFactory;
use Neos\Flow\Security\AccountRepository;
use Neos\Neos\Domain\Service\UserService;
use PHPUnit\Framework\Attributes\Test;

/**
 * Workspaces, their changes and events need a content repository, which the Testing context (SQLite) doesn't have: these tests cover everything
 * up to the content repository lookup
 */
class WorkspacesTest extends EndpointTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->objectManager->get(UserService::class)->createUser('nobody', 'password', 'Nora', 'Nobody', []);
        $this->addMachineClient('nobody-machine', 'nobody');
        // editors may create workspaces and change or delete the ones they manage
        $this->objectManager->get(UserService::class)->createUser('editor', 'password', 'Edda', 'Editor', ['Neos.Neos:Editor']);
        $this->addMachineClient('editor-machine', 'editor');
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

    #[Test]
    public function changesOfUnknownContentRepositoriesAreNotFound(): void
    {
        $response = $this->get('/api/cr/unknown/workspaces/live/changes', $this->token('nobody-machine', 'workspaces.read'));

        self::assertSame(404, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('There is no content repository with the ID unknown', self::json($response)['detail']);
    }

    #[Test]
    public function rejectsInvalidChangeParameters(): void
    {
        $token = $this->token('nobody-machine', 'workspaces.read');

        self::assertSame(400, $this->get('/api/cr/default/workspaces/Not-A-Name/changes', $token)->getStatusCode());
        self::assertSame(400, $this->get('/api/cr/default/workspaces/live/changes?offset=-1', $token)->getStatusCode());
        self::assertSame(400, $this->get('/api/cr/default/workspaces/live/changes?limit=101', $token)->getStatusCode());
    }

    #[Test]
    public function documentsTheChangesUnderTheWorkspacesTag(): void
    {
        $operation = self::json($this->get('/api/openapi.json', null))['paths']['/cr/{contentRepositoryId}/workspaces/{workspaceName}/changes']['get'];
        self::assertSame('listWorkspaceChanges', $operation['operationId']);
        self::assertSame(['Workspaces'], $operation['tags']);
        self::assertSame(['contentRepositoryId', 'workspaceName', 'offset', 'limit'], array_column($operation['parameters'], 'name'));
    }

    #[Test]
    public function changesRequireTheWorkspacesScope(): void
    {
        $response = $this->get('/api/cr/default/workspaces/live/changes', $this->token('nobody-machine', 'nodes.read'));

        self::assertSame(403, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringContainsString('workspaces.read', self::json($response)['detail']);
    }

    #[Test]
    public function changesRequireAToken(): void
    {
        self::assertSame(401, $this->get('/api/cr/default/workspaces/live/changes', null)->getStatusCode());
    }

    #[Test]
    public function aWorkspaceOfAnUnknownContentRepositoryIsNotFound(): void
    {
        $response = $this->get('/api/cr/unknown/workspaces/live', $this->token('nobody-machine', 'workspaces.read'));

        self::assertSame(404, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('There is no content repository with the ID unknown', self::json($response)['detail']);
    }

    #[Test]
    public function rejectsInvalidWorkspaceNames(): void
    {
        self::assertSame(400, $this->get('/api/cr/default/workspaces/Not_A_Name', $this->token('nobody-machine', 'workspaces.read'))->getStatusCode());
    }

    #[Test]
    public function documentsGetWorkspaceUnderTheWorkspacesTag(): void
    {
        $operation = self::json($this->get('/api/openapi.json', null))['paths']['/cr/{contentRepositoryId}/workspaces/{workspaceName}']['get'];
        self::assertSame('getWorkspace', $operation['operationId']);
        self::assertSame(['Workspaces'], $operation['tags']);
    }

    #[Test]
    public function getWorkspaceRequiresTheWorkspacesScope(): void
    {
        $response = $this->get('/api/cr/default/workspaces/live', $this->token('nobody-machine', 'nodes.read'));

        self::assertSame(403, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringContainsString('workspaces.read', self::json($response)['detail']);
    }

    #[Test]
    public function creatingAWorkspaceInAnUnknownContentRepositoryIsNotFound(): void
    {
        $response = $this->post('/api/cr/unknown/workspaces', $this->token('editor-machine', 'workspaces.create'), ['title' => 'Team']);

        self::assertSame(404, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('There is no content repository with the ID unknown', self::json($response)['detail']);
    }

    #[Test]
    public function rejectsInvalidNewWorkspaces(): void
    {
        $token = $this->token('editor-machine', 'workspaces.create');

        self::assertSame(400, $this->post('/api/cr/default/workspaces', $token, ['title' => 'Team', 'classification' => 'team'])->getStatusCode());
        self::assertSame(400, $this->post('/api/cr/default/workspaces', $token, ['title' => 'Team', 'visibility' => 'public'])->getStatusCode());
        self::assertSame(400, $this->post('/api/cr/default/workspaces', $token, ['title' => 'Team', 'name' => 'Not_A_Name'])->getStatusCode());
        self::assertSame(400, $this->post('/api/cr/default/workspaces', $token, ['title' => 'Team', 'baseWorkspaceName' => 'Not_A_Name'])->getStatusCode());
        self::assertSame(400, $this->post('/api/cr/default/workspaces', $token, ['title' => 'Team', 'owner' => 'someone'])->getStatusCode());
    }

    #[Test]
    public function onlySharedAndPersonalWorkspacesCanBeCreated(): void
    {
        $token = $this->token('editor-machine', 'workspaces.create');

        foreach (['root', 'unknown'] as $classification) {
            $response = $this->post('/api/cr/default/workspaces', $token, ['title' => 'Team', 'classification' => $classification]);
            self::assertSame(422, $response->getStatusCode(), (string)$response->getBody());
            self::assertSame(sprintf('Only shared and personal workspaces can be created, not %s ones', $classification), self::json($response)['detail']);
        }
    }

    #[Test]
    public function aSharedWorkspaceNeedsATitle(): void
    {
        $token = $this->token('editor-machine', 'workspaces.create');

        $response = $this->post('/api/cr/default/workspaces', $token, []);
        self::assertSame(422, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('A shared workspace needs a title', self::json($response)['detail']);

        $response = $this->post('/api/cr/default/workspaces', $token, ['title' => '   ']);
        self::assertSame(422, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('The title must be 1 to 200 letters, digits, spaces and punctuation', self::json($response)['detail']);

        $response = $this->post('/api/cr/default/workspaces', $token, ['title' => 'Team', 'description' => str_repeat('a', 501)]);
        self::assertSame(422, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('The description must be at most 500 letters, digits, spaces and punctuation', self::json($response)['detail']);
    }

    #[Test]
    public function aPersonalWorkspaceHasNoVisibility(): void
    {
        $response = $this->post('/api/cr/default/workspaces', $this->token('editor-machine', 'workspaces.create'), ['classification' => 'personal', 'visibility' => 'private']);

        self::assertSame(422, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('A personal workspace has no visibility, only its owner may work in it', self::json($response)['detail']);
    }

    #[Test]
    public function creatingAWorkspaceNeedsANeosUser(): void
    {
        $accountFactory = $this->objectManager->get(AccountFactory::class);
        $this->objectManager->get(AccountRepository::class)->add($accountFactory->createAccountWithPassword('workspace-service', 'password', ['Neos.Neos:Editor'], 'Neos.Neos:Backend'));
        $this->addMachineClient('workspace-service-machine', 'workspace-service');
        $this->persistenceManager->persistAll();
        $token = $this->token('workspace-service-machine', 'workspaces.create');

        foreach (['shared', 'personal'] as $classification) {
            $response = $this->post('/api/cr/default/workspaces', $token, ['title' => 'Team', 'classification' => $classification]);
            self::assertSame(422, $response->getStatusCode(), (string)$response->getBody());
            self::assertSame('The account has no Neos user to create the workspace for', self::json($response)['detail']);
        }
    }

    #[Test]
    public function documentsCreateWorkspaceUnderTheWorkspacesTag(): void
    {
        $operation = self::json($this->get('/api/openapi.json', null))['paths']['/cr/{contentRepositoryId}/workspaces']['post'];
        self::assertSame('createWorkspace', $operation['operationId']);
        self::assertSame(['Workspaces'], $operation['tags']);
        self::assertSame(['201', '400', '401', '403', '404', '409', '422'], array_map('strval', array_keys($operation['responses'])));
        self::assertArrayHasKey('Location', $operation['responses']['201']['headers']);
    }

    #[Test]
    public function createWorkspaceRequiresTheScope(): void
    {
        $response = $this->post('/api/cr/default/workspaces', $this->token('editor-machine', 'workspaces.read'), ['title' => 'Team']);

        self::assertSame(403, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringContainsString('workspaces.create', self::json($response)['detail']);
    }

    #[Test]
    public function createWorkspaceRequiresThePrivilege(): void
    {
        // nobody has no roles, Neos grants creating workspaces to editors
        $response = $this->post('/api/cr/default/workspaces', $this->token('nobody-machine', 'workspaces.create'), ['title' => 'Team']);

        self::assertSame(403, $response->getStatusCode(), (string)$response->getBody());
    }

    #[Test]
    public function createWorkspaceRequiresAToken(): void
    {
        self::assertSame(401, $this->post('/api/cr/default/workspaces', null, ['title' => 'Team'])->getStatusCode());
    }

    #[Test]
    public function updatingAWorkspaceOfAnUnknownContentRepositoryIsNotFound(): void
    {
        $response = $this->patch('/api/cr/unknown/workspaces/team', $this->token('editor-machine', 'workspaces.update'), ['title' => 'Team']);

        self::assertSame(404, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('There is no content repository with the ID unknown', self::json($response)['detail']);
    }

    #[Test]
    public function rejectsInvalidWorkspaceUpdates(): void
    {
        $token = $this->token('editor-machine', 'workspaces.update');

        self::assertSame(400, $this->patch('/api/cr/default/workspaces/Not_A_Name', $token, ['title' => 'Team'])->getStatusCode());
        self::assertSame(400, $this->patch('/api/cr/default/workspaces/team', $token, ['title' => 42])->getStatusCode());
        self::assertSame(400, $this->patch('/api/cr/default/workspaces/team', $token, ['description' => ['a']])->getStatusCode());
    }

    #[Test]
    public function documentsUpdateWorkspaceUnderTheWorkspacesTag(): void
    {
        $document = self::json($this->get('/api/openapi.json', null));
        $operation = $document['paths']['/cr/{contentRepositoryId}/workspaces/{workspaceName}']['patch'];

        self::assertSame('updateWorkspace', $operation['operationId']);
        self::assertSame(['Workspaces'], $operation['tags']);
        foreach (['200', '403', '404', '422'] as $status) {
            self::assertArrayHasKey($status, $operation['responses']);
        }
        // the base workspace is changed by checkoutWorkspace, not here
        self::assertSame(['title', 'description'], array_keys($document['components']['schemas']['WorkspaceUpdate']['properties']));
    }

    #[Test]
    public function updateWorkspaceRequiresTheScope(): void
    {
        $response = $this->patch('/api/cr/default/workspaces/team', $this->token('editor-machine', 'workspaces.read'), ['title' => 'Team']);

        self::assertSame(403, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringContainsString('workspaces.update', self::json($response)['detail']);
    }

    #[Test]
    public function updateWorkspaceRequiresAToken(): void
    {
        self::assertSame(401, $this->patch('/api/cr/default/workspaces/team', null, ['title' => 'Team'])->getStatusCode());
    }

    #[Test]
    public function deletingAWorkspaceOfAnUnknownContentRepositoryIsNotFound(): void
    {
        $response = $this->delete('/api/cr/unknown/workspaces/shared-one?force=true', $this->token('editor-machine', 'workspaces.delete'));

        self::assertSame(404, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('There is no content repository with the ID unknown', self::json($response)['detail']);
    }

    #[Test]
    public function rejectsInvalidDeleteWorkspaceParameters(): void
    {
        $token = $this->token('editor-machine', 'workspaces.delete');

        self::assertSame(400, $this->delete('/api/cr/default/workspaces/Not_A_Name', $token)->getStatusCode());
        self::assertSame(400, $this->delete('/api/cr/default/workspaces/shared-one?force=yes', $token)->getStatusCode());
    }

    #[Test]
    public function documentsDeleteWorkspaceUnderTheWorkspacesTag(): void
    {
        $operation = self::json($this->get('/api/openapi.json', null))['paths']['/cr/{contentRepositoryId}/workspaces/{workspaceName}']['delete'];
        self::assertSame('deleteWorkspace', $operation['operationId']);
        self::assertSame(['Workspaces'], $operation['tags']);
        foreach (['204', '403', '404', '409', '422'] as $status) {
            self::assertArrayHasKey($status, $operation['responses']);
        }
        $force = array_values(array_filter($operation['parameters'], static fn (array $parameter) => $parameter['name'] === 'force'))[0];
        self::assertSame('query', $force['in']);
        self::assertFalse($force['required'] ?? false);
        self::assertSame('boolean', $force['schema']['type']);
    }

    #[Test]
    public function deleteWorkspaceRequiresTheWorkspacesDeleteScope(): void
    {
        $response = $this->delete('/api/cr/default/workspaces/shared-one', $this->token('editor-machine', 'workspaces.read'));

        self::assertSame(403, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringContainsString('workspaces.delete', self::json($response)['detail']);
    }

    #[Test]
    public function deleteWorkspaceRequiresAToken(): void
    {
        self::assertSame(401, $this->delete('/api/cr/default/workspaces/shared-one', null)->getStatusCode());
    }

    #[Test]
    public function checkingOutAWorkspaceOfAnUnknownContentRepositoryIsNotFound(): void
    {
        $response = $this->post('/api/cr/unknown/workspaces/user-checkout/checkout', $this->token('editor-machine', 'workspaces.update'), ['baseWorkspaceName' => 'live']);

        self::assertSame(404, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('There is no content repository with the ID unknown', self::json($response)['detail']);
    }

    #[Test]
    public function rejectsInvalidCheckouts(): void
    {
        $token = $this->token('editor-machine', 'workspaces.update');

        self::assertSame(400, $this->post('/api/cr/default/workspaces/Not_A_Name/checkout', $token, ['baseWorkspaceName' => 'live'])->getStatusCode());
        self::assertSame(400, $this->post('/api/cr/default/workspaces/user-checkout/checkout', $token, [])->getStatusCode());
        self::assertSame(400, $this->post('/api/cr/default/workspaces/user-checkout/checkout', $token, ['baseWorkspaceName' => 'Not_A_Name'])->getStatusCode());
    }

    #[Test]
    public function documentsTheCheckoutUnderTheWorkspacesTag(): void
    {
        $document = self::json($this->get('/api/openapi.json', null));
        $operation = $document['paths']['/cr/{contentRepositoryId}/workspaces/{workspaceName}/checkout']['post'];
        self::assertSame('checkoutWorkspace', $operation['operationId']);
        self::assertSame(['Workspaces'], $operation['tags']);
        self::assertSame(['contentRepositoryId', 'workspaceName'], array_column($operation['parameters'], 'name'));
        self::assertSame('#/components/schemas/WorkspaceCheckout', $operation['requestBody']['content']['application/json']['schema']['$ref']);
        self::assertSame(['baseWorkspaceName'], $document['components']['schemas']['WorkspaceCheckout']['required']);
        foreach (['200', '403', '404', '409', '422'] as $status) {
            self::assertArrayHasKey($status, $operation['responses']);
        }
    }

    #[Test]
    public function checkoutRequiresTheUpdateScope(): void
    {
        $response = $this->post('/api/cr/default/workspaces/user-checkout/checkout', $this->token('editor-machine', 'workspaces.read'), ['baseWorkspaceName' => 'live']);

        self::assertSame(403, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringContainsString('workspaces.update', self::json($response)['detail']);
    }

    #[Test]
    public function checkoutRequiresAToken(): void
    {
        self::assertSame(401, $this->post('/api/cr/default/workspaces/user-checkout/checkout', null, ['baseWorkspaceName' => 'live'])->getStatusCode());
    }
}
