<?php
declare(strict_types=1);

namespace Neos\Api\Tests\Functional\Endpoints;

use Doctrine\ORM\EntityManagerInterface;
use Neos\Api\Domain\User\EmailAddress;
use Neos\Neos\Domain\Service\UserService;
use Neos\Party\Domain\Model\ElectronicAddress;
use PHPUnit\Framework\Attributes\Test;

class UsersTest extends EndpointTestCase
{
    private const string UNKNOWN_ID = '00000000-0000-4000-8000-000000000000';

    private string $adminId;

    private string $editorId;

    protected function setUp(): void
    {
        parent::setUp();
        $userService = $this->objectManager->get(UserService::class);
        $admin = $userService->createUser('admin', 'password', 'Ada', 'Admin', ['Neos.Neos:Administrator']);
        $email = new ElectronicAddress();
        $email->setIdentifier('ada@example.com');
        $email->setType(EmailAddress::ELECTRONIC_ADDRESS_TYPE);
        $admin->addElectronicAddress($email);
        $admin->setPrimaryElectronicAddress($email);
        $userService->updateUser($admin);
        $this->adminId = $admin->getId()->value;

        $this->editorId = $userService->createUser('editor', 'password', 'Edith', 'Editor', ['Neos.Neos:Editor'])->getId()->value;
        $userService->createUser('manager', 'password', 'Manu', 'Manager', ['Neos.Neos:UserManager']);

        $this->addMachineClient('admin-machine', 'admin');
        $this->addMachineClient('editor-machine', 'editor');
        $this->addMachineClient('manager-machine', 'manager');
        $this->persistenceManager->persistAll();
    }

    #[Test]
    public function listsAllUsersForEditors(): void
    {
        $response = $this->get('/api/users', $this->token('editor-machine', 'users.read'));

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        $users = self::json($response);
        self::assertSame(['Ada Admin', 'Edith Editor', 'Manu Manager'], array_column($users, 'label'));
        self::assertSame([
            'id' => $this->adminId,
            'label' => 'Ada Admin',
            'firstName' => 'Ada',
            'lastName' => 'Admin',
            'email' => 'ada@example.com',
            'active' => true,
            'accounts' => [['identifier' => 'admin', 'roles' => ['Neos.Neos:Administrator']]],
        ], $users[0]);
        self::assertNull($users[1]['email']);
    }

    #[Test]
    public function getsAUserForEditors(): void
    {
        $response = $this->get('/api/users/' . $this->adminId, $this->token('editor-machine', 'users.read'));

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('Ada Admin', self::json($response)['label']);
    }

    #[Test]
    public function readingIsDeniedToNonEditors(): void
    {
        $token = $this->token('manager-machine', 'users.read');
        foreach (['/api/users', '/api/users/' . $this->adminId] as $path) {
            $response = $this->get($path, $token);

            self::assertSame(403, $response->getStatusCode(), $path . ': ' . $response->getBody());
            self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
            self::assertStringContainsString('Neos.Api:Users.Read', self::json($response)['detail']);
        }
    }

    #[Test]
    public function unknownUsersAreNotFound(): void
    {
        $response = $this->get('/api/users/' . self::UNKNOWN_ID, $this->token('editor-machine', 'users.read'));
        self::assertSame(404, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));

        $response = $this->patch('/api/users/' . self::UNKNOWN_ID, $this->token('admin-machine', 'users.update'), ['firstName' => 'Nobody']);
        self::assertSame(404, $response->getStatusCode(), (string)$response->getBody());
    }

    #[Test]
    public function rejectsInvalidUserIds(): void
    {
        self::assertSame(400, $this->get('/api/users/not-a-uuid', $this->token('editor-machine', 'users.read'))->getStatusCode());
    }

    #[Test]
    public function administratorsChangeUsers(): void
    {
        $response = $this->patch('/api/users/' . $this->editorId, $this->token('admin-machine', 'users.update'), ['firstName' => 'Edda', 'email' => 'edda@example.com']);

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        $user = self::json($response);
        self::assertSame(['Edda Editor', 'Edda', 'Editor', 'edda@example.com'], [$user['label'], $user['firstName'], $user['lastName'], $user['email']]);

        // persisted: a new request reads it back
        $this->persistenceManager->clearState();
        $user = self::json($this->get('/api/users/' . $this->editorId, $this->token('editor-machine', 'users.read')));
        self::assertSame(['Edda Editor', 'edda@example.com'], [$user['label'], $user['email']]);
    }

    #[Test]
    public function changesTheExistingEmailAddress(): void
    {
        $response = $this->patch('/api/users/' . $this->adminId, $this->token('admin-machine', 'users.update'), ['email' => 'ada@example.org']);

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame(['Ada Admin', 'ada@example.org'], [self::json($response)['label'], self::json($response)['email']]);
    }

    #[Test]
    public function changingIsDeniedToEditors(): void
    {
        $response = $this->patch('/api/users/' . $this->editorId, $this->token('editor-machine', 'users.update'), ['firstName' => 'Evil']);

        self::assertSame(403, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringContainsString('Neos.Api:Users.Update', self::json($response)['detail']);
    }

    #[Test]
    public function changingRequiresTheUpdateScope(): void
    {
        $response = $this->patch('/api/users/' . $this->editorId, $this->token('admin-machine', 'users.read'), ['firstName' => 'Edda']);

        self::assertSame(403, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringContainsString('users.update', self::json($response)['detail']);
    }

    #[Test]
    public function rejectsInvalidChanges(): void
    {
        $token = $this->token('admin-machine', 'users.update');
        foreach ([['email' => 'not-an-email'], ['firstName' => 42], ['password' => 'secret']] as $body) {
            $response = $this->patch('/api/users/' . $this->editorId, $token, $body);

            self::assertSame(400, $response->getStatusCode(), json_encode($body) . ': ' . $response->getBody());
        }
    }

    #[Test]
    public function administratorsCreateUsers(): void
    {
        $response = $this->post('/api/users', $this->token('admin-machine', 'users.create'), [
            'username' => 'writer',
            'password' => 'writer-password',
            'firstName' => 'Wanda',
            'lastName' => 'Writer',
            'email' => 'wanda@example.com',
            'roles' => ['Neos.Neos:RestrictedEditor'],
        ]);

        self::assertSame(201, $response->getStatusCode(), (string)$response->getBody());
        $user = self::json($response);
        self::assertSame(['Wanda Writer', 'wanda@example.com', [['identifier' => 'writer', 'roles' => ['Neos.Neos:RestrictedEditor']]]], [$user['label'], $user['email'], $user['accounts']]);
        self::assertArrayNotHasKey('password', $user);
        self::assertSame('users/' . $user['id'], $response->getHeaderLine('Location'));

        $this->persistenceManager->clearState();
        $response = $this->get('/api/users/' . $user['id'], $this->token('editor-machine', 'users.read'));
        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertTrue($this->objectManager->get(UserService::class)->getUser('writer')->isActive());
    }

    #[Test]
    public function newUsersAreEditorsByDefault(): void
    {
        $response = $this->post('/api/users', $this->token('admin-machine', 'users.create'), ['username' => 'writer', 'password' => 'writer-password', 'firstName' => 'Wanda', 'lastName' => 'Writer']);

        self::assertSame(201, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame(['Neos.Neos:Editor'], self::json($response)['accounts'][0]['roles']);
        self::assertNull(self::json($response)['email']);
    }

    #[Test]
    public function usernamesAreUnique(): void
    {
        $response = $this->post('/api/users', $this->token('admin-machine', 'users.create'), ['username' => 'editor', 'password' => 'password', 'firstName' => 'Eve', 'lastName' => 'Editor']);

        self::assertSame(409, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function rejectsUnknownAndAbstractRoles(): void
    {
        $token = $this->token('admin-machine', 'users.create');
        foreach (['Neos.Neos:Nonexistent', 'Neos.Flow:Everybody'] as $role) {
            $response = $this->post('/api/users', $token, ['username' => 'writer', 'password' => 'password', 'firstName' => 'Wanda', 'lastName' => 'Writer', 'roles' => [$role]]);

            self::assertSame(422, $response->getStatusCode(), $role . ': ' . $response->getBody());
            self::assertStringContainsString($role, self::json($response)['detail']);
        }
        $this->persistenceManager->clearState();
        self::assertNull($this->objectManager->get(UserService::class)->getUser('writer'));
    }

    #[Test]
    public function rejectsInvalidNewUsers(): void
    {
        $token = $this->token('admin-machine', 'users.create');
        $valid = ['username' => 'writer', 'password' => 'password', 'firstName' => 'Wanda', 'lastName' => 'Writer'];
        foreach ([['password' => ''], ['username' => ''], ['email' => 'not-an-email'], ['roles' => ['Editor']], ['active' => false]] as $change) {
            $response = $this->post('/api/users', $token, array_merge($valid, $change));

            self::assertSame(400, $response->getStatusCode(), json_encode($change) . ': ' . $response->getBody());
        }
        $body = $valid;
        unset($body['password']);
        self::assertSame(400, $this->post('/api/users', $token, $body)->getStatusCode());
    }

    #[Test]
    public function creatingIsDeniedToEditorsAndRequiresTheCreateScope(): void
    {
        $body = ['username' => 'writer', 'password' => 'password', 'firstName' => 'Wanda', 'lastName' => 'Writer'];

        $response = $this->post('/api/users', $this->token('editor-machine', 'users.create'), $body);
        self::assertSame(403, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringContainsString('Neos.Api:Users.Create', self::json($response)['detail']);

        $response = $this->post('/api/users', $this->token('admin-machine', 'users.update'), $body);
        self::assertSame(403, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringContainsString('users.create', self::json($response)['detail']);
    }

    #[Test]
    public function administratorsDeleteUsers(): void
    {
        $this->createWorkspaceMetadataTable();
        $response = $this->delete('/api/users/' . $this->editorId, $this->token('admin-machine', 'users.delete'));

        self::assertSame(204, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('', (string)$response->getBody());
        $this->persistenceManager->clearState();
        self::assertNull($this->objectManager->get(UserService::class)->getUser('editor'));
        self::assertSame(404, $this->get('/api/users/' . $this->editorId, $this->token('admin-machine', 'users.read'))->getStatusCode());
    }

    #[Test]
    public function usersCantDeleteThemselves(): void
    {
        $response = $this->delete('/api/users/' . $this->adminId, $this->token('admin-machine', 'users.delete'));

        self::assertSame(409, $response->getStatusCode(), (string)$response->getBody());
        self::assertNotNull($this->objectManager->get(UserService::class)->getUser('admin'));
    }

    #[Test]
    public function deletingIsDeniedToEditorsAndRequiresTheDeleteScope(): void
    {
        $response = $this->delete('/api/users/' . $this->adminId, $this->token('editor-machine', 'users.delete'));
        self::assertSame(403, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringContainsString('Neos.Api:Users.Delete', self::json($response)['detail']);

        $response = $this->delete('/api/users/' . $this->editorId, $this->token('admin-machine', 'users.update'));
        self::assertSame(403, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringContainsString('users.delete', self::json($response)['detail']);
    }

    #[Test]
    public function deletingUnknownUsersIsNotFound(): void
    {
        self::assertSame(404, $this->delete('/api/users/' . self::UNKNOWN_ID, $this->token('admin-machine', 'users.delete'))->getStatusCode());
    }

    /**
     * UserService::deleteUser() looks up the personal workspaces in a table of a Neos migration, which the testing
     * schema lacks: a stand-in with the columns it reads
     */
    private function createWorkspaceMetadataTable(): void
    {
        $this->objectManager->get(EntityManagerInterface::class)->getConnection()->executeStatement(
            'CREATE TABLE IF NOT EXISTS neos_neos_workspace_metadata (content_repository_id VARCHAR(16), workspace_name VARCHAR(255), classification VARCHAR(255), owner_user_id VARCHAR(255))',
        );
    }

    #[Test]
    public function readingRequiresTheReadScope(): void
    {
        $response = $this->get('/api/users', $this->token('admin-machine', null));

        self::assertSame(403, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringContainsString('users.read', self::json($response)['detail']);
    }

    #[Test]
    public function requiresAToken(): void
    {
        self::assertSame(401, $this->get('/api/users', null)->getStatusCode());
        self::assertSame(401, $this->patch('/api/users/' . $this->editorId, null, ['firstName' => 'Edda'])->getStatusCode());
        self::assertSame(401, $this->post('/api/users', null, ['username' => 'writer', 'password' => 'password', 'firstName' => 'Wanda', 'lastName' => 'Writer'])->getStatusCode());
        self::assertSame(401, $this->delete('/api/users/' . $this->editorId, null)->getStatusCode());
    }

    #[Test]
    public function isDescribedInTheSpecification(): void
    {
        $document = self::json($this->get('/api/openapi.json', null));

        self::assertSame([['oauth2' => ['users.read']]], $document['paths']['/users']['get']['security']);
        self::assertSame([['oauth2' => ['users.update']]], $document['paths']['/users/{userId}']['patch']['security']);
        self::assertSame([['oauth2' => ['users.create']]], $document['paths']['/users']['post']['security']);
        self::assertSame([['oauth2' => ['users.delete']]], $document['paths']['/users/{userId}']['delete']['security']);
        self::assertSame(['oauth2'], array_keys($document['components']['securitySchemes']));
        self::assertSame(['listUsers', 'createUser', 'getUser', 'updateUser', 'deleteUser'], [
            $document['paths']['/users']['get']['operationId'],
            $document['paths']['/users']['post']['operationId'],
            $document['paths']['/users/{userId}']['get']['operationId'],
            $document['paths']['/users/{userId}']['patch']['operationId'],
            $document['paths']['/users/{userId}']['delete']['operationId'],
        ]);
        // neos/openapi doesn't know about the 403 of missing scopes or privileges yet
        self::assertSame([200, 400, 401, 404], array_keys($document['paths']['/users/{userId}']['patch']['responses']));
        $created = $document['paths']['/users']['post']['responses'];
        self::assertEqualsCanonicalizing([201, 400, 401, 409, 422], array_keys($created));
        self::assertArrayHasKey('Location', $created[201]['headers']);
        self::assertEqualsCanonicalizing([204, 400, 401, 404, 409], array_keys($document['paths']['/users/{userId}']['delete']['responses']));
        self::assertTrue($document['components']['schemas']['Password']['writeOnly']);
        self::assertArrayHasKey('UserUpdate', $document['components']['schemas']);
        self::assertSame('Change the Neos users (privilege Neos.Api:Users.Update)', $document['components']['securitySchemes']['oauth2']['flows']['clientCredentials']['scopes']['users.update']);
    }
}
