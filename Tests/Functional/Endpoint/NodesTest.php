<?php
declare(strict_types=1);

namespace Neos\Api\Tests\Functional\Endpoint;

use Neos\Neos\Domain\Service\UserService;
use PHPUnit\Framework\Attributes\Test;

/**
 * Nodes need a content repository, which the Testing context (SQLite) doesn't have: these tests cover everything up
 * to the node lookup
 */
class NodesTest extends EndpointTestCase
{
    private const string NODE_ADDRESS = '{"contentRepositoryId":"unknown","workspaceName":"live","dimensionSpacePoint":{},"aggregateId":"some-node"}';

    protected function setUp(): void
    {
        parent::setUp();
        $this->objectManager->get(UserService::class)->createUser('nobody', 'password', 'Nora', 'Nobody', []);
        $this->addMachineClient('nobody-machine', 'nobody');
        $this->persistenceManager->persistAll();
    }

    #[Test]
    public function nodesOfUnknownContentRepositoriesAreNotFound(): void
    {
        // every account may read nodes, which ones is up to its node privileges
        $response = $this->get(self::nodePath(self::NODE_ADDRESS), $this->token('nobody-machine', 'nodes.read'));

        self::assertSame(404, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringStartsWith('There is no node {"contentRepositoryId":"unknown"', self::json($response)['detail']);
    }

    #[Test]
    public function rejectsInvalidNodeAddresses(): void
    {
        $token = $this->token('nobody-machine', 'nodes.read');
        // not even an object, its schema rejects it
        self::assertSame(400, $this->get(self::nodePath('not-json'), $token)->getStatusCode());
        // an encoded slash stays in the path segment
        $response = $this->get(self::nodePath(str_replace('{}', '{"path":"a/b"}', self::NODE_ADDRESS)), $token);
        self::assertSame(404, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringStartsWith('There is no node', self::json($response)['detail']);

        foreach (['{not json}', '{"contentRepositoryId":"default"}', '{"contentRepositoryId":"default","workspaceName":"live","dimensionSpacePoint":"en","aggregateId":"some-node"}'] as $nodeAddress) {
            $response = $this->get(self::nodePath($nodeAddress), $token);
            self::assertSame(400, $response->getStatusCode(), $nodeAddress . ': ' . $response->getBody());
            self::assertStringStartsWith('The node address is invalid', self::json($response)['detail']);
        }
    }

    #[Test]
    public function includesTheReferencesChildrenAndVariants(): void
    {
        $token = $this->token('nobody-machine', 'nodes.read');

        self::assertSame(404, $this->get(self::nodePath(self::NODE_ADDRESS) . '?include=references,children.references,variants,variants.references', $token)->getStatusCode());

        $response = $this->get(self::nodePath(self::NODE_ADDRESS) . '?include=references,parent,children.children', $token);
        self::assertSame(400, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('Can\'t include parent, children.children, only: references, children, children.references, variants, variants.references', self::json($response)['detail']);

        // not a list of paths, its schema rejects it
        self::assertSame(400, $this->get(self::nodePath(self::NODE_ADDRESS) . '?include=references,', $token)->getStatusCode());
    }

    #[Test]
    public function requiresTheScope(): void
    {
        $response = $this->get(self::nodePath(self::NODE_ADDRESS), $this->token('nobody-machine', 'me.read'));

        self::assertSame(403, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringContainsString('nodes.read', self::json($response)['detail']);
    }

    #[Test]
    public function requiresAToken(): void
    {
        self::assertSame(401, $this->get(self::nodePath(self::NODE_ADDRESS), null)->getStatusCode());
    }

    private static function nodePath(string $nodeAddress): string
    {
        return '/api/nodes/' . rawurlencode($nodeAddress);
    }
}
