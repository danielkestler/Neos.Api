<?php
declare(strict_types=1);

namespace Neos\Api\Tests\Functional\Endpoint;

use Neos\Neos\Domain\Service\UserService;
use PHPUnit\Framework\Attributes\Test;

/**
 * Rendering needs a content repository, which the Testing context (SQLite) doesn't have: these tests cover everything
 * up to the node lookup
 */
class ViewsTest extends EndpointTestCase
{
    private const string NODE_ADDRESS = '{"contentRepositoryId":"default","workspaceName":"live","dimensionSpacePoint":{},"aggregateId":"some-node"}';

    protected function setUp(): void
    {
        parent::setUp();
        $userService = $this->objectManager->get(UserService::class);
        $userService->createUser('editor', 'password', 'Edith', 'Editor', ['Neos.Neos:Editor']);
        $userService->createUser('nobody', 'password', 'Nora', 'Nobody', []);
        $this->addMachineClient('editor-machine', 'editor');
        $this->addMachineClient('nobody-machine', 'nobody');
        $this->persistenceManager->persistAll();
    }

    #[Test]
    public function listsTheViewsOfTheSettings(): void
    {
        $response = $this->get('/api/views', $this->token('editor-machine', 'views.read'));

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        // the package's own first, the removed one is left out
        self::assertSame(['documentNodesTree', 'contentNodesTree', 'document', 'navigation'], array_column(self::json($response)['data'], 'name'));
        self::assertSame(['name' => 'navigation', 'description' => 'A test view'], self::json($response)['data'][3]);
        self::assertSame(['total' => 4], self::json($response)['meta']);
    }

    #[Test]
    public function unknownViewsAreNotFound(): void
    {
        $response = $this->get(self::viewPath(self::NODE_ADDRESS, 'unknown'), $this->token('editor-machine', 'views.read'));

        self::assertSame(404, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('There is no view unknown', self::json($response)['detail']);
        self::assertSame(404, $this->get(self::viewPath(self::NODE_ADDRESS, 'removed'), $this->token('editor-machine', 'views.read'))->getStatusCode());
    }

    #[Test]
    public function rejectsInvalidNodeAddresses(): void
    {
        $token = $this->token('editor-machine', 'views.read');
        // not even an object, its schema rejects it
        self::assertSame(400, $this->get(self::viewPath('not-json', 'navigation'), $token)->getStatusCode());
        // a slash is fine in a query parameter: the address is read, its (unknown) content repository looked up
        $response = $this->get(self::viewPath('{"contentRepositoryId":"unknown","workspaceName":"live","dimensionSpacePoint":{"path":"a/b"},"aggregateId":"some-node"}', 'navigation'), $token);
        self::assertSame(404, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringStartsWith('There is no node', self::json($response)['detail']);

        foreach (['{not json}', '{"contentRepositoryId":"default"}', '{"contentRepositoryId":"default","workspaceName":"live","dimensionSpacePoint":"en","aggregateId":"some-node"}'] as $nodeAddress) {
            $response = $this->get(self::viewPath($nodeAddress, 'navigation'), $token);
            self::assertSame(400, $response->getStatusCode(), $nodeAddress . ': ' . $response->getBody());
            self::assertStringStartsWith('The node address is invalid', self::json($response)['detail']);
        }
    }

    #[Test]
    public function nodesOfUnknownContentRepositoriesAreNotFound(): void
    {
        $response = $this->get(self::viewPath(str_replace('"default"', '"unknown"', self::NODE_ADDRESS), 'navigation'), $this->token('editor-machine', 'views.read'));

        self::assertSame(404, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringStartsWith('There is no node', self::json($response)['detail']);
    }

    #[Test]
    public function withoutANodeTheDefaultSiteIsNeeded(): void
    {
        // there is no site in the Testing context
        $response = $this->get(self::viewPath(null, 'navigation'), $this->token('editor-machine', 'views.read'));

        self::assertSame(404, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('There is no site to render the view for, give a nodeAddress', self::json($response)['detail']);
    }

    #[Test]
    public function rejectsUnknownRenderingModes(): void
    {
        $response = $this->get(self::viewPath(self::NODE_ADDRESS, 'navigation') . '&renderingMode=unknown', $this->token('editor-machine', 'views.read'));

        self::assertSame(400, $response->getStatusCode(), (string)$response->getBody());
        // site packages may add modes
        self::assertStringStartsWith('There is no rendering mode unknown, there are: frontend, inPlace, rawContent, desktop', self::json($response)['detail']);
    }

    #[Test]
    public function backendRenderingModesNeedBackendAccess(): void
    {
        $path = self::viewPath(str_replace('"default"', '"unknown"', self::NODE_ADDRESS), 'navigation');

        // every account may render views in the frontend mode
        $response = $this->get($path . '&renderingMode=frontend', $this->token('nobody-machine', 'views.read'));
        self::assertSame('There is no node', substr(self::json($response)['detail'], 0, 16));

        $response = $this->get($path . '&renderingMode=inPlace', $this->token('nobody-machine', 'views.read'));
        self::assertSame(403, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('The rendering mode inPlace needs the privilege Neos.Neos:Backend.GeneralAccess', self::json($response)['detail']);

        // editors have it: the request gets as far as the node lookup
        $response = $this->get($path . '&renderingMode=inPlace', $this->token('editor-machine', 'views.read'));
        self::assertSame(404, $response->getStatusCode(), (string)$response->getBody());
    }

    #[Test]
    public function requiresTheScope(): void
    {
        $token = $this->token('editor-machine', 'me.read');

        foreach (['/api/views', self::viewPath(self::NODE_ADDRESS, 'navigation')] as $path) {
            $response = $this->get($path, $token);
            self::assertSame(403, $response->getStatusCode(), (string)$response->getBody());
            self::assertStringContainsString('views.read', self::json($response)['detail']);
        }
    }

    #[Test]
    public function requiresAToken(): void
    {
        self::assertSame(401, $this->get('/api/views', null)->getStatusCode());
        self::assertSame(401, $this->get(self::viewPath(self::NODE_ADDRESS, 'navigation'), null)->getStatusCode());
    }

    private static function viewPath(string|null $nodeAddress, string $viewName): string
    {
        return sprintf('/api/views/%s', $viewName) . ($nodeAddress !== null ? '?nodeAddress=' . rawurlencode($nodeAddress) : '');
    }
}
