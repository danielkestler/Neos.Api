<?php
declare(strict_types=1);

namespace Neos\Api\Tests\Functional\Feature;

use Neos\Flow\Security\AccountFactory;
use Neos\Flow\Security\AccountRepository;
use Neos\Neos\Domain\Service\UserService;
use PHPUnit\Framework\Attributes\Test;

class MeTest extends EndpointTestCase
{
    private string $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $user = $this->objectManager->get(UserService::class)->createUser('editor', 'password', 'Edith', 'Editor', ['Neos.Neos:Editor']);
        $this->userId = $user->getId()->value;

        $accountFactory = $this->objectManager->get(AccountFactory::class);
        $this->objectManager->get(AccountRepository::class)->add($accountFactory->createAccountWithPassword('service', 'password', ['Neos.Neos:Editor'], 'Neos.Neos:Backend'));

        $this->addMachineClient('editor-machine', 'editor');
        $this->addMachineClient('service-machine', 'service');
        $this->persistenceManager->persistAll();
    }

    #[Test]
    public function returnsTheAccountOfTheToken(): void
    {
        $response = $this->get('/api/me', $this->token('editor-machine', 'me.read'));

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        $account = ['identifier' => 'editor', 'roles' => ['Neos.Neos:Editor']];
        self::assertSame([
            'account' => $account,
            'user' => [
                'id' => $this->userId,
                'label' => 'Edith Editor',
                'firstName' => 'Edith',
                'lastName' => 'Editor',
                'email' => null,
                'active' => true,
                'accounts' => [$account],
            ],
            'token' => ['client' => 'editor-machine', 'scopes' => ['me.read']],
        ], self::json($response));
    }

    #[Test]
    public function userIsNullForAccountsWithoutUser(): void
    {
        $response = $this->get('/api/me', $this->token('service-machine', 'me.read'));

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        $body = self::json($response);
        self::assertSame('service', $body['account']['identifier']);
        self::assertNull($body['user']);
    }

    #[Test]
    public function requiresAToken(): void
    {
        $response = $this->get('/api/me', null);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('Bearer resource_metadata="http://localhost/.well-known/oauth-protected-resource/api"', $response->getHeaderLine('WWW-Authenticate'));
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));

        self::assertSame(401, $this->get('/api/me', 'not-a-token')->getStatusCode());
    }

    #[Test]
    public function requiresTheReadScope(): void
    {
        $response = $this->get('/api/me', $this->token('editor-machine', null));

        self::assertSame(403, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringContainsString('me.read', self::json($response)['detail']);
    }

    #[Test]
    public function otherScopesDoNotGrantIt(): void
    {
        $response = $this->get('/api/me', $this->token('editor-machine', 'users.read users.update'));

        self::assertSame(403, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringContainsString('me.read', self::json($response)['detail']);
    }

    #[Test]
    public function isDescribedInTheSpecification(): void
    {
        $response = $this->get('/api/openapi.json', null);

        self::assertSame(200, $response->getStatusCode());
        $document = self::json($response);
        self::assertSame([['oauth2' => ['me.read']]], $document['paths']['/me']['get']['security']);
        self::assertSame('oauth2', $document['components']['securitySchemes']['oauth2']['type']);
        self::assertArrayHasKey('CurrentAccount', $document['components']['schemas']);
    }
}
