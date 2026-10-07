<?php
declare(strict_types=1);

namespace Neos\Api\Tests\Functional\Endpoint;

use Neos\Neos\Domain\Service\UserService;
use PHPUnit\Framework\Attributes\Test;

/**
 * Nodes need a content repository, which the Testing context (SQLite) doesn't have: these tests cover everything up
 * to the content repository lookup
 */
class NodesTest extends EndpointTestCase
{
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
        $token = $this->token('nobody-machine', 'nodes.read');

        foreach ([self::nodePath('some-node'), self::nodePath('some-node', ['workspaceName' => 'user-editor', 'dimensionSpacePoint' => '{"language":"de"}']), self::listPath([])] as $path) {
            $response = $this->get($path, $token);
            self::assertSame(404, $response->getStatusCode(), $path . ': ' . $response->getBody());
            self::assertSame('There is no content repository with the ID unknown', self::json($response)['detail']);
        }
    }

    #[Test]
    public function rejectsInvalidParameters(): void
    {
        $token = $this->token('nobody-machine', 'nodes.read');
        // their schemas reject them
        self::assertSame(400, $this->get(self::nodePath('Not_An_Id'), $token)->getStatusCode());
        self::assertSame(400, $this->get('/api/cr/Not-An-Id/nodes/some-node', $token)->getStatusCode());
        foreach (['workspaceName' => 'Not A Workspace', 'dimensionSpacePoint' => 'de'] as $name => $value) {
            self::assertSame(400, $this->get(self::nodePath('some-node', [$name => $value]), $token)->getStatusCode(), $name);
            self::assertSame(400, $this->get(self::listPath([$name => $value]), $token)->getStatusCode(), $name);
        }

        // syntax errors before the content repository is looked up
        foreach (['{not json}', '{"language":["de"]}'] as $dimensionSpacePoint) {
            foreach ([self::nodePath('some-node', ['dimensionSpacePoint' => $dimensionSpacePoint]), self::listPath(['dimensionSpacePoint' => $dimensionSpacePoint])] as $path) {
                $response = $this->get($path, $token);
                self::assertSame(400, $response->getStatusCode(), $path . ': ' . $response->getBody());
                self::assertStringStartsWith('dimensionSpacePoint is invalid', self::json($response)['detail']);
            }
        }
    }

    #[Test]
    public function includesTheReferencesChildrenAndVariants(): void
    {
        $token = $this->token('nobody-machine', 'nodes.read');

        self::assertSame(404, $this->get(self::nodePath('some-node', ['include' => 'references,children.references,variants,variants.references']), $token)->getStatusCode());

        $response = $this->get(self::nodePath('some-node', ['include' => 'references,parent,children.children']), $token);
        self::assertSame(400, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('Can\'t include parent, children.children, only: references, children, children.references, variants, variants.references', self::json($response)['detail']);

        // not a list of paths, its schema rejects it
        self::assertSame(400, $this->get(self::nodePath('some-node', ['include' => 'references,']), $token)->getStatusCode());
    }

    #[Test]
    public function filtersByHierarchyOrReference(): void
    {
        $token = $this->token('nobody-machine', 'nodes.read');
        $hierarchy = ['type' => 'parent', 'aggregateId' => 'some-node'];

        $response = $this->get(self::listPath(['filterByHierarchy' => $hierarchy, 'filterByReference' => ['aggregateId' => 'other-node']]), $token);
        self::assertSame(400, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('filterByHierarchy and filterByReference can\'t be combined', self::json($response)['detail']);

        // valid ones get as far as the content repository lookup, without either it's the default site of the content repository
        foreach ([
            [],
            ['filterByHierarchy' => $hierarchy],
            ['filterByHierarchy' => ['type' => 'ancestor', 'aggregateId' => 'some-node']],
            ['filterByReference' => ['aggregateId' => 'some-node']],
            ['filterByReference' => ['aggregateId' => 'some-node', 'name' => 'relatedPages']],
            ['filterByNodeType' => 'Neos.Neos:Document,!Neos.Neos:Shortcut', 'search' => 'neos'],
        ] as $query) {
            $response = $this->get(self::listPath($query + ['workspaceName' => 'live', 'dimensionSpacePoint' => '{"language":"de"}']), $token);
            self::assertSame(404, $response->getStatusCode(), json_encode($query) . ': ' . $response->getBody());
        }

        // their schemas reject them: an unknown type, missing or unknown members, not an aggregate id, empty strings
        foreach ([
            ['filterByHierarchy' => ['type' => 'child', 'aggregateId' => 'some-node']],
            ['filterByHierarchy' => ['aggregateId' => 'some-node']],
            ['filterByHierarchy' => ['type' => 'parent']],
            ['filterByHierarchy' => $hierarchy + ['unknown' => 'x']],
            ['filterByHierarchy' => ['type' => 'parent', 'aggregateId' => 'Not_An_Id']],
            ['filterByReference' => ['name' => 'relatedPages']],
            ['filterByReference' => ['aggregateId' => 'some-node', 'name' => '']],
            ['filterByNodeType' => ''],
            ['filterByProperty' => ''],
            ['search' => ''],
        ] as $query) {
            self::assertSame(400, $this->get(self::listPath($query), $token)->getStatusCode(), json_encode($query));
        }
    }

    #[Test]
    public function rejectsInvalidFiltersSortsAndPages(): void
    {
        $token = $this->token('nobody-machine', 'nodes.read');
        $filter = ['filterByHierarchy' => ['type' => 'parent', 'aggregateId' => 'some-node']];

        $response = $this->get(self::listPath($filter + ['filterByProperty' => 'title = ']), $token);
        self::assertSame(400, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringStartsWith('filterByProperty is invalid', self::json($response)['detail']);
        self::assertSame(404, $this->get(self::listPath($filter + ['filterByProperty' => 'title *= \'Neos\' AND NOT (hideInMenu = true)']), $token)->getStatusCode());

        $response = $this->get(self::listPath($filter + ['sort' => '-timestamps.lastModified,properties.title,label']), $token);
        self::assertSame(400, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('Can\'t sort by label, only by properties.<name> and timestamps.created, timestamps.lastModified, timestamps.originalCreated, timestamps.originalLastModified', self::json($response)['detail']);
        self::assertSame(404, $this->get(self::listPath($filter + ['sort' => '-timestamps.lastModified,properties.title']), $token)->getStatusCode());
        // not a list of fields, its schema rejects it
        self::assertSame(400, $this->get(self::listPath($filter + ['sort' => 'title,']), $token)->getStatusCode());

        $response = $this->get(self::listPath($filter + ['include' => 'children,parent']), $token);
        self::assertSame(400, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringStartsWith('Can\'t include parent', self::json($response)['detail']);

        self::assertSame(404, $this->get(self::listPath($filter + ['page' => ['offset' => '50', 'limit' => '100']]), $token)->getStatusCode());
        foreach ([['limit' => '101'], ['limit' => '0'], ['offset' => '-1'], ['size' => '10']] as $page) {
            $response = $this->get(self::listPath($filter + ['page' => $page]), $token);
            self::assertSame(400, $response->getStatusCode(), json_encode($page) . ': ' . $response->getBody());
        }
    }

    #[Test]
    public function documentsFiltersAndPagesAsDeepObjects(): void
    {
        $parameters = array_column(self::json($this->get('/api/openapi.json', null))['paths']['/cr/{contentRepositoryId}/nodes']['get']['parameters'], null, 'name');

        foreach (['filterByHierarchy', 'filterByReference', 'page'] as $name) {
            self::assertSame('deepObject', $parameters[$name]['style'] ?? null, $name);
            self::assertTrue($parameters[$name]['explode'] ?? null, $name);
        }
        foreach (['filterByNodeType', 'filterByProperty', 'search', 'sort', 'workspaceName', 'dimensionSpacePoint'] as $name) {
            self::assertArrayNotHasKey('style', $parameters[$name], $name);
        }
    }

    #[Test]
    public function requiresTheScope(): void
    {
        foreach ([self::nodePath('some-node'), self::listPath(['filterByHierarchy' => ['type' => 'parent', 'aggregateId' => 'some-node']])] as $path) {
            $response = $this->get($path, $this->token('nobody-machine', 'me.read'));

            self::assertSame(403, $response->getStatusCode(), (string)$response->getBody());
            self::assertStringContainsString('nodes.read', self::json($response)['detail']);
        }
    }

    #[Test]
    public function requiresAToken(): void
    {
        self::assertSame(401, $this->get(self::nodePath('some-node'), null)->getStatusCode());
        self::assertSame(401, $this->get(self::listPath([]), null)->getStatusCode());
    }

    /**
     * @param array<string, mixed> $query
     */
    private static function listPath(array $query): string
    {
        return '/api/cr/unknown/nodes' . self::query($query);
    }

    /**
     * @param array<string, mixed> $query
     */
    private static function nodePath(string $aggregateId, array $query = []): string
    {
        return '/api/cr/unknown/nodes/' . rawurlencode($aggregateId) . self::query($query);
    }

    /**
     * @param array<string, mixed> $query
     */
    private static function query(array $query): string
    {
        return $query !== [] ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '';
    }
}
