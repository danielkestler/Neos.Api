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
    public function listsFromAtMostOneNode(): void
    {
        $token = $this->token('nobody-machine', 'nodes.read');

        $response = $this->get(self::listPath(['filter' => ['parent' => 'some-node', 'ancestor' => 'other-node']]), $token);
        self::assertSame(400, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('At most one of filter[parent], filter[ancestor] and filter[referencing] is allowed', self::json($response)['detail']);

        // without one it's the default site of the content repository
        foreach ([[], ['parent' => 'some-node'], ['ancestor' => 'some-node'], ['referencing' => 'some-node'], ['nodeType' => 'Neos.Neos:Document']] as $filter) {
            $response = $this->get(self::listPath(['filter' => $filter, 'workspaceName' => 'live', 'dimensionSpacePoint' => '{"language":"de"}']), $token);
            self::assertSame(404, $response->getStatusCode(), json_encode($filter) . ': ' . $response->getBody());
        }

        $response = $this->get(self::listPath(['filter' => ['parent' => 'some-node', 'referenceName' => 'relatedPages']]), $token);
        self::assertSame(400, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('filter[referenceName] needs filter[referencing]', self::json($response)['detail']);

        // not an aggregate id, unknown filter members, the workspace and dimension space point aren't filters: its schema rejects them
        foreach ([['parent' => '{"aggregateId":"some-node"}'], ['parent' => 'some-node', 'unknown' => 'x'], ['workspaceName' => 'live'], ['dimensionSpacePoint' => '{}']] as $filter) {
            self::assertSame(400, $this->get(self::listPath(['filter' => $filter]), $token)->getStatusCode(), json_encode($filter));
        }
    }

    #[Test]
    public function rejectsInvalidFiltersSortsAndPages(): void
    {
        $token = $this->token('nobody-machine', 'nodes.read');
        $filter = ['parent' => 'some-node'];

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
        $parameters = array_column(self::json($this->get('/api/openapi.json', null))['paths']['/cr/{contentRepositoryId}/nodes']['get']['parameters'], null, 'name');

        foreach (['filter', 'page'] as $name) {
            self::assertSame('deepObject', $parameters[$name]['style'] ?? null, $name);
            self::assertTrue($parameters[$name]['explode'] ?? null, $name);
        }
        foreach (['sort', 'workspaceName', 'dimensionSpacePoint'] as $name) {
            self::assertArrayNotHasKey('style', $parameters[$name], $name);
        }
    }

    #[Test]
    public function requiresTheScope(): void
    {
        foreach ([self::nodePath('some-node'), self::listPath(['filter' => ['parent' => 'some-node']])] as $path) {
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
