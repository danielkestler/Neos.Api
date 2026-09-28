<?php
declare(strict_types=1);

namespace Neos\Api\Tests\Functional\Endpoints;

use GuzzleHttp\Psr7\ServerRequest;
use Neos\Flow\Security\AccountFactory;
use Neos\Flow\Security\AccountRepository;
use Neos\Flow\Security\Authentication\AuthenticationManagerInterface;
use Neos\Flow\Security\Authentication\Token\BearerToken;
use Neos\Flow\Security\Authentication\TokenAndProviderFactoryInterface;
use Neos\Flow\Tests\FunctionalTestCase;
use Neos\Neos\Domain\Service\UserService;
use Neos\OAuth\Domain\Model\OAuthClient;
use Neos\OAuth\Domain\Repository\OAuthClientRepository;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;

class MeTest extends FunctionalTestCase
{
    protected static $testablePersistenceEnabled = true;

    private const string SECRET = 'machine-secret';

    private string $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $user = $this->objectManager->get(UserService::class)->createUser('editor', 'password', 'Edith', 'Editor', ['Neos.Neos:Editor']);
        $this->userId = $user->getId()->value;

        $accountFactory = $this->objectManager->get(AccountFactory::class);
        $this->objectManager->get(AccountRepository::class)->add($accountFactory->createAccountWithPassword('service', 'password', ['Neos.Neos:Editor'], 'Neos.Neos:Backend'));

        $clientRepository = $this->objectManager->get(OAuthClientRepository::class);
        $clientRepository->add(new OAuthClient('editor-machine', 'Editor machine', password_hash(self::SECRET, PASSWORD_DEFAULT), [], [OAuthClient::GRANT_CLIENT_CREDENTIALS], ['neos.read'], false, 'editor'));
        $clientRepository->add(new OAuthClient('service-machine', 'Service machine', password_hash(self::SECRET, PASSWORD_DEFAULT), [], [OAuthClient::GRANT_CLIENT_CREDENTIALS], ['neos.read'], false, 'service'));
        $this->persistenceManager->persistAll();
    }

    #[Test]
    public function returnsTheAccountOfTheToken(): void
    {
        $response = $this->get('/api/me', $this->token('editor-machine', 'neos.read'));

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame([
            'account' => ['identifier' => 'editor', 'roles' => ['Neos.Neos:Editor']],
            'user' => ['id' => $this->userId, 'label' => 'Edith Editor'],
            'token' => ['client' => 'editor-machine', 'scopes' => ['neos.read']],
        ], json_decode((string)$response->getBody(), true, flags: JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function userIsNullForAccountsWithoutUser(): void
    {
        $response = $this->get('/api/me', $this->token('service-machine', 'neos.read'));

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        $body = json_decode((string)$response->getBody(), true, flags: JSON_THROW_ON_ERROR);
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
        self::assertStringContainsString('neos.read', json_decode((string)$response->getBody(), true)['detail']);
    }

    #[Test]
    public function isDescribedInTheSpecification(): void
    {
        $response = $this->get('/api/openapi.json', null);

        self::assertSame(200, $response->getStatusCode());
        $document = json_decode((string)$response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame([['oauth2' => ['neos.read']]], $document['paths']['/me']['get']['security']);
        self::assertSame('oauth2', $document['components']['securitySchemes']['oauth2']['type']);
        self::assertArrayHasKey('MeResponse', $document['components']['schemas']);
    }

    private function token(string $clientIdentifier, ?string $scope): string
    {
        $arguments = ['grant_type' => 'client_credentials', 'client_id' => $clientIdentifier, 'client_secret' => self::SECRET];
        if ($scope !== null) {
            $arguments['scope'] = $scope;
        }
        $response = $this->browser->request('http://localhost/oauth/token', 'POST', $arguments);
        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        return json_decode((string)$response->getBody(), true, flags: JSON_THROW_ON_ERROR)['access_token'];
    }

    private function get(string $path, ?string $bearer): ResponseInterface
    {
        $this->resetAuthenticationState();
        return $this->browser->sendRequest(new ServerRequest('GET', 'http://localhost' . $path, $bearer !== null ? ['Authorization' => 'Bearer ' . $bearer] : []));
    }

    /**
     * The browser runs all requests in one process: forget the previous request's authentication
     */
    private function resetAuthenticationState(): void
    {
        $this->inject($this->objectManager->get(AuthenticationManagerInterface::class), 'isAuthenticated', null);
        foreach ($this->objectManager->get(TokenAndProviderFactoryInterface::class)->getTokens() as $token) {
            if ($token instanceof BearerToken) {
                $this->inject($token, 'credentials', ['bearer' => '']);
            }
        }
    }
}
