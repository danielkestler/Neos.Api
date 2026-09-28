<?php
declare(strict_types=1);

namespace Neos\Api\Tests\Functional\Endpoints;

use Neos\Api\Domain\EmailAddress;
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
        $response = $this->get('/api/users', $this->token('editor-machine', 'neos.read'));

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        $users = self::json($response)['users'];
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
        $response = $this->get('/api/users/' . $this->adminId, $this->token('editor-machine', 'neos.read'));

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('Ada Admin', self::json($response)['label']);
    }

    #[Test]
    public function readingIsDeniedToNonEditors(): void
    {
        $token = $this->token('manager-machine', 'neos.read');
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
        $response = $this->get('/api/users/' . self::UNKNOWN_ID, $this->token('editor-machine', 'neos.read'));
        self::assertSame(404, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));

        $response = $this->patch('/api/users/' . self::UNKNOWN_ID, $this->token('admin-machine', 'neos.write'), ['firstName' => 'Nobody']);
        self::assertSame(404, $response->getStatusCode(), (string)$response->getBody());
    }

    #[Test]
    public function rejectsInvalidUserIds(): void
    {
        self::assertSame(400, $this->get('/api/users/not-a-uuid', $this->token('editor-machine', 'neos.read'))->getStatusCode());
    }

    #[Test]
    public function administratorsChangeUsers(): void
    {
        $response = $this->patch('/api/users/' . $this->editorId, $this->token('admin-machine', 'neos.write'), ['firstName' => 'Edda', 'email' => 'edda@example.com']);

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        $user = self::json($response);
        self::assertSame(['Edda Editor', 'Edda', 'Editor', 'edda@example.com'], [$user['label'], $user['firstName'], $user['lastName'], $user['email']]);

        // persisted: a new request reads it back
        $this->persistenceManager->clearState();
        $user = self::json($this->get('/api/users/' . $this->editorId, $this->token('editor-machine', 'neos.read')));
        self::assertSame(['Edda Editor', 'edda@example.com'], [$user['label'], $user['email']]);
    }

    #[Test]
    public function changesTheExistingEmailAddress(): void
    {
        $response = $this->patch('/api/users/' . $this->adminId, $this->token('admin-machine', 'neos.write'), ['email' => 'ada@example.org']);

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame(['Ada Admin', 'ada@example.org'], [self::json($response)['label'], self::json($response)['email']]);
    }

    #[Test]
    public function changingIsDeniedToEditors(): void
    {
        $response = $this->patch('/api/users/' . $this->editorId, $this->token('editor-machine', 'neos.write'), ['firstName' => 'Evil']);

        self::assertSame(403, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringContainsString('Neos.Api:Users.Write', self::json($response)['detail']);
    }

    #[Test]
    public function changingRequiresTheWriteScope(): void
    {
        $response = $this->patch('/api/users/' . $this->editorId, $this->token('admin-machine', 'neos.read'), ['firstName' => 'Edda']);

        self::assertSame(403, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringContainsString('neos.write', self::json($response)['detail']);
    }

    #[Test]
    public function rejectsInvalidChanges(): void
    {
        $token = $this->token('admin-machine', 'neos.write');
        foreach ([['email' => 'not-an-email'], ['firstName' => 42], ['password' => 'secret']] as $body) {
            $response = $this->patch('/api/users/' . $this->editorId, $token, $body);

            self::assertSame(400, $response->getStatusCode(), json_encode($body) . ': ' . $response->getBody());
        }
    }

    #[Test]
    public function readingRequiresTheReadScope(): void
    {
        $response = $this->get('/api/users', $this->token('admin-machine', null));

        self::assertSame(403, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringContainsString('neos.read', self::json($response)['detail']);
    }

    #[Test]
    public function requiresAToken(): void
    {
        self::assertSame(401, $this->get('/api/users', null)->getStatusCode());
        self::assertSame(401, $this->patch('/api/users/' . $this->editorId, null, ['firstName' => 'Edda'])->getStatusCode());
    }

    #[Test]
    public function isDescribedInTheSpecification(): void
    {
        $document = self::json($this->get('/api/openapi.json', null));

        self::assertSame([['oauth2' => ['neos.read'], 'neosPrivileges' => ['Neos.Api:Users.Read']]], $document['paths']['/users']['get']['security']);
        self::assertSame([['oauth2' => ['neos.write'], 'neosPrivileges' => ['Neos.Api:Users.Write']]], $document['paths']['/users/{userId}']['patch']['security']);
        self::assertSame('http', $document['components']['securitySchemes']['neosPrivileges']['type']);
        self::assertSame(['listUsers', 'getUser', 'updateUser'], [
            $document['paths']['/users']['get']['operationId'],
            $document['paths']['/users/{userId}']['get']['operationId'],
            $document['paths']['/users/{userId}']['patch']['operationId'],
        ]);
        // neos/openapi doesn't know about the 403 of missing scopes or privileges yet
        self::assertSame([200, 400, 401, 404], array_keys($document['paths']['/users/{userId}']['patch']['responses']));
        self::assertArrayHasKey('UserPatch', $document['components']['schemas']);
        self::assertContains('neos.write', array_keys($document['components']['securitySchemes']['oauth2']['flows']['clientCredentials']['scopes']));
    }
}
