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
        $userService = $this->objectManager->get(UserService::class);
        $userService->createUser('nobody', 'password', 'Nora', 'Nobody', []);
        $userService->createUser('editor', 'password', 'Edith', 'Editor', ['Neos.Neos:Editor']);
        $this->addMachineClient('nobody-machine', 'nobody');
        $this->addMachineClient('editor-machine', 'editor');
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
        $hierarchy = ['type' => 'parent', 'nodeAggregateId' => 'some-node'];

        $response = $this->get(self::listPath(['filterByHierarchy' => $hierarchy, 'filterByReference' => ['nodeAggregateId' => 'other-node']]), $token);
        self::assertSame(400, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('filterByHierarchy and filterByReference can\'t be combined', self::json($response)['detail']);

        // valid ones get as far as the content repository lookup, without either it's the default site of the content repository
        foreach ([
            [],
            ['filterByHierarchy' => $hierarchy],
            ['filterByHierarchy' => ['type' => 'ancestor', 'nodeAggregateId' => 'some-node']],
            ['filterByReference' => ['nodeAggregateId' => 'some-node']],
            ['filterByReference' => ['nodeAggregateId' => 'some-node', 'name' => 'relatedPages']],
            ['filterByNodeType' => 'Neos.Neos:Document,!Neos.Neos:Shortcut', 'search' => 'neos'],
        ] as $query) {
            $response = $this->get(self::listPath($query + ['workspaceName' => 'live', 'dimensionSpacePoint' => '{"language":"de"}']), $token);
            self::assertSame(404, $response->getStatusCode(), json_encode($query) . ': ' . $response->getBody());
        }

        // their schemas reject them: an unknown type, missing or unknown members, not an aggregate id, empty strings
        foreach ([
            ['filterByHierarchy' => ['type' => 'child', 'nodeAggregateId' => 'some-node']],
            ['filterByHierarchy' => ['nodeAggregateId' => 'some-node']],
            ['filterByHierarchy' => ['type' => 'parent']],
            ['filterByHierarchy' => $hierarchy + ['unknown' => 'x']],
            ['filterByHierarchy' => ['type' => 'parent', 'nodeAggregateId' => 'Not_An_Id']],
            ['filterByReference' => ['name' => 'relatedPages']],
            ['filterByReference' => ['nodeAggregateId' => 'some-node', 'name' => '']],
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
        $filter = ['filterByHierarchy' => ['type' => 'parent', 'nodeAggregateId' => 'some-node']];

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

        self::assertSame(404, $this->get(self::listPath($filter + ['offset' => '50', 'limit' => '100']), $token)->getStatusCode());
        foreach ([['limit' => '101'], ['limit' => '0'], ['offset' => '-1'], ['limit' => 'ten']] as $page) {
            $response = $this->get(self::listPath($filter + $page), $token);
            self::assertSame(400, $response->getStatusCode(), json_encode($page) . ': ' . $response->getBody());
        }
    }

    #[Test]
    public function documentsOnlyObjectFiltersAsDeepObjects(): void
    {
        $parameters = array_column(self::json($this->get('/api/openapi.json', null))['paths']['/cr/{contentRepositoryId}/nodes']['get']['parameters'], null, 'name');

        foreach (['filterByHierarchy', 'filterByReference'] as $name) {
            self::assertSame('deepObject', $parameters[$name]['style'] ?? null, $name);
            self::assertTrue($parameters[$name]['explode'] ?? null, $name);
        }
        foreach (['filterByNodeType', 'filterByProperty', 'search', 'sort', 'offset', 'limit', 'workspaceName', 'dimensionSpacePoint'] as $name) {
            self::assertArrayNotHasKey('style', $parameters[$name], $name);
        }
    }

    #[Test]
    public function requiresTheScope(): void
    {
        foreach ([self::nodePath('some-node'), self::listPath(['filterByHierarchy' => ['type' => 'parent', 'nodeAggregateId' => 'some-node']])] as $path) {
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

    #[Test]
    public function updatesPropertiesOnlyInAGivenWorkspace(): void
    {
        $token = $this->token('editor-machine', 'nodes.update');
        $body = ['title' => 'Home', 'teaser' => null];

        $response = $this->patch(self::propertiesPath('some-node', ['workspaceName' => 'user-editor']), $token, $body);
        self::assertSame(404, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('There is no content repository with the ID unknown', self::json($response)['detail']);
        self::assertSame(404, $this->patch(self::propertiesPath('some-node', ['workspaceName' => 'user-editor', 'dimensionSpacePoint' => '{"language":"de"}']), $token, $body)->getStatusCode());

        // no default workspace for changes
        $response = $this->patch(self::propertiesPath('some-node'), $token, $body);
        self::assertSame(400, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringContainsString('workspaceName', (string)$response->getBody());

        $parameters = array_column(self::json($this->get('/api/openapi.json', null))['paths']['/cr/{contentRepositoryId}/nodes/{nodeAggregateId}/properties']['patch']['parameters'], null, 'name');
        self::assertTrue($parameters['workspaceName']['required']);
        self::assertFalse($parameters['dimensionSpacePoint']['required'] ?? false);
    }

    #[Test]
    public function rejectsInvalidPropertyUpdates(): void
    {
        $token = $this->token('editor-machine', 'nodes.update');
        $path = self::propertiesPath('some-node', ['workspaceName' => 'user-editor']);

        // the body's schema rejects anything but a map, e.g. a list
        self::assertSame(400, $this->patch($path, $token, ['title', 'Home'])->getStatusCode());

        $response = $this->patch(self::propertiesPath('some-node', ['workspaceName' => 'user-editor', 'dimensionSpacePoint' => '{not json}']), $token, ['title' => 'Home']);
        self::assertSame(400, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringStartsWith('dimensionSpacePoint is invalid', self::json($response)['detail']);
    }

    #[Test]
    public function updatingPropertiesRequiresTheScopeAndThePrivilege(): void
    {
        $path = self::propertiesPath('some-node', ['workspaceName' => 'user-editor']);
        $body = ['title' => 'Home'];

        // reading isn't enough
        $response = $this->patch($path, $this->token('editor-machine', 'nodes.read'), $body);
        self::assertSame(403, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringContainsString('nodes.update', self::json($response)['detail']);

        // only editors may change nodes
        self::assertSame(403, $this->patch($path, $this->token('nobody-machine', 'nodes.update'), $body)->getStatusCode());

        self::assertSame(401, $this->patch($path, null, $body)->getStatusCode());
    }

    #[Test]
    public function createsNodesOnlyInAGivenWorkspace(): void
    {
        $token = $this->token('editor-machine', 'nodes.create');
        $body = ['nodeType' => 'Neos.Neos:Page', 'parentNodeAggregateId' => 'some-node', 'properties' => ['title' => 'About us']];

        foreach ([['workspaceName' => 'user-editor'], ['workspaceName' => 'user-editor', 'dimensionSpacePoint' => '{"language":"de"}']] as $query) {
            $response = $this->post(self::listPath($query), $token, $body);
            self::assertSame(404, $response->getStatusCode(), (string)$response->getBody());
            self::assertSame('There is no content repository with the ID unknown', self::json($response)['detail']);
        }
        $all = $body + ['succeedingSiblingNodeAggregateId' => 'other-node', 'nodeAggregateId' => 'new-node'];
        self::assertSame(404, $this->post(self::listPath(['workspaceName' => 'user-editor']), $token, $all)->getStatusCode());

        // no default workspace for changes
        $response = $this->post(self::listPath([]), $token, $body);
        self::assertSame(400, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringContainsString('workspaceName', (string)$response->getBody());

        $post = self::json($this->get('/api/openapi.json', null))['paths']['/cr/{contentRepositoryId}/nodes']['post'];
        self::assertSame('createNode', $post['operationId']);
        self::assertArrayHasKey('Location', $post['responses']['201']['headers']);
    }

    #[Test]
    public function rejectsInvalidNewNodes(): void
    {
        $token = $this->token('editor-machine', 'nodes.create');
        $path = self::listPath(['workspaceName' => 'user-editor']);
        $body = ['nodeType' => 'Neos.Neos:Page', 'parentNodeAggregateId' => 'some-node'];

        // the body's schema rejects them: no node type or parent, unknown fields, invalid ids, properties no map
        foreach ([
            ['parentNodeAggregateId' => 'some-node'],
            ['nodeType' => 'Neos.Neos:Page'],
            $body + ['nodeName' => 'about-us'],
            ['nodeType' => 'Neos.Neos:Page', 'parentNodeAggregateId' => 'Not_An_Id'],
            $body + ['nodeAggregateId' => 'Not_An_Id'],
            $body + ['properties' => 'title'],
        ] as $invalid) {
            self::assertSame(400, $this->post($path, $token, $invalid)->getStatusCode(), json_encode($invalid));
        }
    }

    #[Test]
    public function creatingRequiresTheScopeAndThePrivilege(): void
    {
        $path = self::listPath(['workspaceName' => 'user-editor']);
        $body = ['nodeType' => 'Neos.Neos:Page', 'parentNodeAggregateId' => 'some-node'];

        // changing isn't enough
        $response = $this->post($path, $this->token('editor-machine', 'nodes.update'), $body);
        self::assertSame(403, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringContainsString('nodes.create', self::json($response)['detail']);

        // only editors may create nodes
        self::assertSame(403, $this->post($path, $this->token('nobody-machine', 'nodes.create'), $body)->getStatusCode());

        self::assertSame(401, $this->post($path, null, $body)->getStatusCode());
    }

    #[Test]
    public function deletesNodesOnlyInAGivenWorkspace(): void
    {
        $token = $this->token('editor-machine', 'nodes.delete');

        foreach ([['workspaceName' => 'user-editor'], ['workspaceName' => 'user-editor', 'dimensionSpacePoint' => '{"language":"de"}']] as $query) {
            $response = $this->delete(self::nodePath('some-node', $query), $token);
            self::assertSame(404, $response->getStatusCode(), (string)$response->getBody());
            self::assertSame('There is no content repository with the ID unknown', self::json($response)['detail']);
        }

        // no default workspace for changes
        $response = $this->delete(self::nodePath('some-node'), $token);
        self::assertSame(400, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringContainsString('workspaceName', (string)$response->getBody());
        self::assertSame(400, $this->delete(self::nodePath('Not_An_Id', ['workspaceName' => 'user-editor']), $token)->getStatusCode());

        $delete = self::json($this->get('/api/openapi.json', null))['paths']['/cr/{contentRepositoryId}/nodes/{nodeAggregateId}']['delete'];
        self::assertSame('deleteNode', $delete['operationId']);
        self::assertArrayHasKey('204', $delete['responses']);
    }

    #[Test]
    public function deletingRequiresTheScopeAndThePrivilege(): void
    {
        $path = self::nodePath('some-node', ['workspaceName' => 'user-editor']);

        // changing isn't enough
        $response = $this->delete($path, $this->token('editor-machine', 'nodes.update'));
        self::assertSame(403, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringContainsString('nodes.delete', self::json($response)['detail']);

        // only editors may delete nodes
        self::assertSame(403, $this->delete($path, $this->token('nobody-machine', 'nodes.delete'))->getStatusCode());

        self::assertSame(401, $this->delete($path, null)->getStatusCode());
    }

    #[Test]
    public function tagsNodesOnlyInAGivenWorkspace(): void
    {
        $token = $this->token('editor-machine', 'nodes.update');
        $workspace = ['workspaceName' => 'user-editor'];

        $response = $this->post(self::tagsPath('some-node', $workspace), $token, ['tag' => 'disabled']);
        self::assertSame(404, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('There is no content repository with the ID unknown', self::json($response)['detail']);
        self::assertSame(404, $this->delete(self::tagsPath('some-node', $workspace, 'disabled'), $token)->getStatusCode());

        // no default workspace for changes
        self::assertSame(400, $this->post(self::tagsPath('some-node', []), $token, ['tag' => 'disabled'])->getStatusCode());
        self::assertSame(400, $this->delete(self::tagsPath('some-node', [], 'disabled'), $token)->getStatusCode());

        // removed is up to deleteNode and the trash
        foreach ([$this->post(self::tagsPath('some-node', $workspace), $token, ['tag' => 'removed']), $this->delete(self::tagsPath('some-node', $workspace, 'removed'), $token)] as $response) {
            self::assertSame(422, $response->getStatusCode(), (string)$response->getBody());
        }

        // not a tag, their schemas reject them
        foreach (['Disabled', 'with space', str_repeat('a', 37)] as $tag) {
            self::assertSame(400, $this->post(self::tagsPath('some-node', $workspace), $token, ['tag' => $tag])->getStatusCode(), $tag);
        }
        self::assertSame(400, $this->delete(self::tagsPath('some-node', $workspace, 'Disabled'), $token)->getStatusCode());
        self::assertSame(400, $this->post(self::tagsPath('some-node', $workspace), $token, [])->getStatusCode());
    }

    #[Test]
    public function taggingRequiresTheScopeAndThePrivilege(): void
    {
        $workspace = ['workspaceName' => 'user-editor'];

        $response = $this->post(self::tagsPath('some-node', $workspace), $this->token('editor-machine', 'nodes.read'), ['tag' => 'disabled']);
        self::assertSame(403, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringContainsString('nodes.update', self::json($response)['detail']);
        self::assertSame(403, $this->delete(self::tagsPath('some-node', $workspace, 'disabled'), $this->token('nobody-machine', 'nodes.update'))->getStatusCode());
        self::assertSame(401, $this->post(self::tagsPath('some-node', $workspace), null, ['tag' => 'disabled'])->getStatusCode());
    }

    #[Test]
    public function restoresNodesOnlyInAGivenWorkspace(): void
    {
        $token = $this->token('editor-machine', 'nodes.delete');

        $response = $this->post(self::restorePath('some-node', ['workspaceName' => 'user-editor']), $token, []);
        self::assertSame(404, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('There is no content repository with the ID unknown', self::json($response)['detail']);

        // no default workspace for changes
        self::assertSame(400, $this->post(self::restorePath('some-node', []), $token, [])->getStatusCode());
        self::assertSame(400, $this->post(self::restorePath('Not_An_Id', ['workspaceName' => 'user-editor']), $token, [])->getStatusCode());
    }

    #[Test]
    public function restoringRequiresTheScopeAndThePrivilege(): void
    {
        $path = self::restorePath('some-node', ['workspaceName' => 'user-editor']);

        $response = $this->post($path, $this->token('editor-machine', 'nodes.update'), []);
        self::assertSame(403, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringContainsString('nodes.delete', self::json($response)['detail']);
        self::assertSame(403, $this->post($path, $this->token('nobody-machine', 'nodes.delete'), [])->getStatusCode());
        self::assertSame(401, $this->post($path, null, [])->getStatusCode());
    }

    #[Test]
    public function changesNodeTypesOnlyInAGivenWorkspace(): void
    {
        $token = $this->token('editor-machine', 'nodes.update');
        $body = ['nodeType' => 'Neos.Neos:Shortcut'];

        $response = $this->put(self::nodeTypePath('some-node', ['workspaceName' => 'user-editor']), $token, $body);
        self::assertSame(404, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('There is no content repository with the ID unknown', self::json($response)['detail']);

        // no default workspace for changes
        self::assertSame(400, $this->put(self::nodeTypePath('some-node', []), $token, $body)->getStatusCode());
        // their schemas reject them
        foreach ([[], ['nodeType' => ''], $body + ['strategy' => 'delete']] as $invalid) {
            self::assertSame(400, $this->put(self::nodeTypePath('some-node', ['workspaceName' => 'user-editor']), $token, $invalid)->getStatusCode(), json_encode($invalid));
        }
    }

    #[Test]
    public function changingNodeTypesRequiresTheScopeAndThePrivilege(): void
    {
        $path = self::nodeTypePath('some-node', ['workspaceName' => 'user-editor']);
        $body = ['nodeType' => 'Neos.Neos:Shortcut'];

        $response = $this->put($path, $this->token('editor-machine', 'nodes.read'), $body);
        self::assertSame(403, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringContainsString('nodes.update', self::json($response)['detail']);
        self::assertSame(403, $this->put($path, $this->token('nobody-machine', 'nodes.update'), $body)->getStatusCode());
        self::assertSame(401, $this->put($path, null, $body)->getStatusCode());
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
    private static function nodePath(string $nodeAggregateId, array $query = []): string
    {
        return '/api/cr/unknown/nodes/' . rawurlencode($nodeAggregateId) . self::query($query);
    }

    /**
     * @param array<string, mixed> $query
     */
    private static function propertiesPath(string $nodeAggregateId, array $query = []): string
    {
        return '/api/cr/unknown/nodes/' . rawurlencode($nodeAggregateId) . '/properties' . self::query($query);
    }

    /**
     * @param array<string, mixed> $query
     */
    private static function tagsPath(string $nodeAggregateId, array $query, ?string $tag = null): string
    {
        return '/api/cr/unknown/nodes/' . rawurlencode($nodeAggregateId) . '/tags' . ($tag !== null ? '/' . rawurlencode($tag) : '') . self::query($query);
    }

    /**
     * @param array<string, mixed> $query
     */
    private static function restorePath(string $nodeAggregateId, array $query): string
    {
        return '/api/cr/unknown/nodes/' . rawurlencode($nodeAggregateId) . '/restore' . self::query($query);
    }

    /**
     * @param array<string, mixed> $query
     */
    private static function nodeTypePath(string $nodeAggregateId, array $query): string
    {
        return '/api/cr/unknown/nodes/' . rawurlencode($nodeAggregateId) . '/nodetype' . self::query($query);
    }

    /**
     * @param array<string, mixed> $query
     */
    private static function query(array $query): string
    {
        return $query !== [] ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '';
    }
}
