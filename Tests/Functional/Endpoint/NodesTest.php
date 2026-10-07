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
    public function listsFromAtMostOneNode(): void
    {
        $token = $this->token('nobody-machine', 'nodes.read');

        $response = $this->get(self::listPath(['filter' => ['parent' => self::NODE_ADDRESS, 'ancestor' => self::NODE_ADDRESS]]), $token);
        self::assertSame(400, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('At most one of filter[parent], filter[ancestor] and filter[referencing] is allowed', self::json($response)['detail']);

        // without one it's the default site, there is no site in the Testing context
        foreach (['/api/nodes', self::listPath(['filter' => ['nodeType' => 'Neos.Neos:Document']])] as $path) {
            $response = $this->get($path, $token);
            self::assertSame(404, $response->getStatusCode(), $path . ': ' . $response->getBody());
            self::assertSame('There is no site to list the nodes of, give filter[parent], filter[ancestor] or filter[referencing]', self::json($response)['detail']);
        }
        foreach (['parent', 'ancestor', 'referencing'] as $entryPoint) {
            $response = $this->get(self::listPath(['filter' => [$entryPoint => self::NODE_ADDRESS]]), $token);
            self::assertSame(404, $response->getStatusCode(), $entryPoint . ': ' . $response->getBody());
            self::assertStringStartsWith('There is no node {"contentRepositoryId":"unknown"', self::json($response)['detail']);
        }

        $response = $this->get(self::listPath(['filter' => ['parent' => self::NODE_ADDRESS, 'referenceName' => 'relatedPages']]), $token);
        self::assertSame(400, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('filter[referenceName] needs filter[referencing]', self::json($response)['detail']);

        $response = $this->get(self::listPath(['filter' => ['parent' => '{not json}']]), $token);
        self::assertSame(400, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringStartsWith('The node address is invalid', self::json($response)['detail']);

        // unknown filter members, its schema rejects them
        self::assertSame(400, $this->get(self::listPath(['filter' => ['parent' => self::NODE_ADDRESS, 'unknown' => 'x']]), $token)->getStatusCode());
    }

    #[Test]
    public function rejectsInvalidFiltersSortsAndPages(): void
    {
        $token = $this->token('nobody-machine', 'nodes.read');
        $filter = ['parent' => self::NODE_ADDRESS];

        $response = $this->get(self::listPath(['filter' => $filter + ['property' => 'title = ']]), $token);
        self::assertSame(400, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringStartsWith('filter[property] is invalid', self::json($response)['detail']);
        self::assertSame(404, $this->get(self::listPath(['filter' => $filter + ['property' => 'title *= \'Neos\' AND NOT (hideInMenu = true)']]), $token)->getStatusCode());

        $response = $this->get(self::listPath(['filter' => $filter, 'sort' => '-timestamps.lastModified,properties.title,label']), $token);
        self::assertSame(400, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('Can\'t sort by label, only by properties.<name> and timestamps.created, timestamps.lastModified, timestamps.originalCreated, timestamps.originalLastModified', self::json($response)['detail']);
        self::assertSame(404, $this->get(self::listPath(['filter' => $filter, 'sort' => '-timestamps.lastModified,properties.title']), $token)->getStatusCode());
        // not a list of fields, its schema rejects it
        self::assertSame(400, $this->get(self::listPath(['filter' => $filter, 'sort' => 'title,']), $token)->getStatusCode());

        $response = $this->get(self::listPath(['filter' => $filter, 'include' => 'children,parent']), $token);
        self::assertSame(400, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringStartsWith('Can\'t include parent', self::json($response)['detail']);

        self::assertSame(404, $this->get(self::listPath(['filter' => $filter, 'page' => ['offset' => '50', 'limit' => '100']]), $token)->getStatusCode());
        foreach ([['limit' => '101'], ['limit' => '0'], ['offset' => '-1'], ['size' => '10']] as $page) {
            $response = $this->get(self::listPath(['filter' => $filter, 'page' => $page]), $token);
            self::assertSame(400, $response->getStatusCode(), json_encode($page) . ': ' . $response->getBody());
        }
    }

    #[Test]
    public function documentsFiltersAndPagesAsDeepObjects(): void
    {
        $parameters = array_column(self::json($this->get('/api/openapi.json', null))['paths']['/nodes']['get']['parameters'], null, 'name');

        foreach (['filter', 'page'] as $name) {
            self::assertSame('deepObject', $parameters[$name]['style'] ?? null, $name);
            self::assertTrue($parameters[$name]['explode'] ?? null, $name);
        }
        self::assertArrayNotHasKey('style', $parameters['sort']);
    }

    #[Test]
    public function requiresTheScope(): void
    {
        foreach ([self::nodePath(self::NODE_ADDRESS), self::listPath(['filter' => ['parent' => self::NODE_ADDRESS]])] as $path) {
            $response = $this->get($path, $this->token('nobody-machine', 'me.read'));

            self::assertSame(403, $response->getStatusCode(), (string)$response->getBody());
            self::assertStringContainsString('nodes.read', self::json($response)['detail']);
        }
    }

    #[Test]
    public function requiresAToken(): void
    {
        self::assertSame(401, $this->get(self::nodePath(self::NODE_ADDRESS), null)->getStatusCode());
        self::assertSame(401, $this->get('/api/nodes', null)->getStatusCode());
    }

    /**
     * @param array<string, mixed> $query
     */
    private static function listPath(array $query): string
    {
        return '/api/nodes?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    private static function nodePath(string $nodeAddress): string
    {
        return '/api/nodes/' . rawurlencode($nodeAddress);
    }
}
