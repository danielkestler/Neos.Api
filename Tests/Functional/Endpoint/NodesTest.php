<?php
declare(strict_types=1);

namespace Neos\Api\Tests\Functional\Endpoint;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Neos\Api\Endpoint\ContentRepositories\Schema\ContentRepositoryId;
use Neos\Api\Endpoint\Nodes\Response\NodeCreated;
use Neos\Api\Endpoint\Nodes\Schema\DimensionSpacePoint;
use Neos\Api\Endpoint\Nodes\Schema\Node;
use Neos\Api\Endpoint\Nodes\Schema\NodeAggregateId;
use Neos\Api\Endpoint\Nodes\Schema\NodeClassification;
use Neos\Api\Endpoint\Nodes\Schema\NodeTimestamps;
use Neos\Api\Endpoint\NodeTypes\Schema\NodeTypeName;
use Neos\Api\Endpoint\Workspaces\Schema\WorkspaceName;
use Neos\Neos\Domain\Service\UserService;
use PHPUnit\Framework\Attributes\Test;

/**
 * Nodes need a content repository, which the Testing context (SQLite) doesn't have: most tests cover everything up
 * to the content repository lookup, the ones that call requireLiveWorkspaceAndAdministrator() need Testing/Ddev (MariaDB), see
 * ContentRepositoryTestCase
 */
class NodesTest extends ContentRepositoryTestCase
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
    public function includesTheReferencesChildrenVariantsAndAncestors(): void
    {
        $token = $this->token('nobody-machine', 'nodes.read');

        self::assertSame(404, $this->get(self::nodePath('some-node', ['include' => 'references,children.references,variants,variants.references,ancestors']), $token)->getStatusCode());

        $response = $this->get(self::nodePath('some-node', ['include' => 'references,parent,children.children,ancestors.references']), $token);
        self::assertSame(400, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('Can\'t include parent, children.children, ancestors.references, only: references, children, children.references, variants, variants.references, ancestors', self::json($response)['detail']);

        // not a list of paths, its schema rejects it
        self::assertSame(400, $this->get(self::nodePath('some-node', ['include' => 'references,']), $token)->getStatusCode());
    }

    #[Test]
    public function filtersByHierarchyOrReference(): void
    {
        $token = $this->token('nobody-machine', 'nodes.read');
        $hierarchy = ['type' => 'childOf', 'nodeAggregateId' => 'some-node'];

        $response = $this->get(self::listPath(['filterByHierarchy' => $hierarchy, 'filterByReference' => ['nodeAggregateId' => 'other-node']]), $token);
        self::assertSame(400, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('filterByHierarchy and filterByReference can\'t be combined', self::json($response)['detail']);

        // valid ones get as far as the content repository lookup, without either it's the default site of the content repository
        foreach ([
            [],
            ['filterByHierarchy' => $hierarchy],
            ['filterByHierarchy' => ['type' => 'descendantOf', 'nodeAggregateId' => 'some-node']],
            ['filterByReference' => ['nodeAggregateId' => 'some-node']],
            ['filterByReference' => ['nodeAggregateId' => 'some-node', 'name' => 'relatedPages']],
            ['filterByNodeType' => 'Neos.Neos:Document,!Neos.Neos:Shortcut', 'search' => 'neos'],
        ] as $query) {
            $response = $this->get(self::listPath($query + ['workspaceName' => 'live', 'dimensionSpacePoint' => '{"language":"de"}']), $token);
            self::assertSame(404, $response->getStatusCode(), json_encode($query) . ': ' . $response->getBody());
        }

        // their schemas reject them: an unknown type (also the former parent and ancestor), missing or unknown members, not an aggregate id, empty strings
        foreach ([
            ['filterByHierarchy' => ['type' => 'child', 'nodeAggregateId' => 'some-node']],
            ['filterByHierarchy' => ['type' => 'parent', 'nodeAggregateId' => 'some-node']],
            ['filterByHierarchy' => ['type' => 'ancestor', 'nodeAggregateId' => 'some-node']],
            ['filterByHierarchy' => ['nodeAggregateId' => 'some-node']],
            ['filterByHierarchy' => ['type' => 'childOf']],
            ['filterByHierarchy' => $hierarchy + ['unknown' => 'x']],
            ['filterByHierarchy' => ['type' => 'childOf', 'nodeAggregateId' => 'Not_An_Id']],
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
        $filter = ['filterByHierarchy' => ['type' => 'childOf', 'nodeAggregateId' => 'some-node']];

        // an empty node type name, before the content repository is looked up
        foreach (['!', 'Neos.Neos:Document,!'] as $nodeTypes) {
            $response = $this->get(self::listPath($filter + ['filterByNodeType' => $nodeTypes]), $token);
            self::assertSame(400, $response->getStatusCode(), $nodeTypes . ': ' . $response->getBody());
            self::assertStringStartsWith('filterByNodeType is invalid', self::json($response)['detail']);
        }

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
        foreach ([self::nodePath('some-node'), self::listPath(['filterByHierarchy' => ['type' => 'childOf', 'nodeAggregateId' => 'some-node']])] as $path) {
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
    public function generatesUriPathSegmentsOnlyInAGivenWorkspace(): void
    {
        $token = $this->token('editor-machine', 'nodes.update');

        $response = $this->post(self::uriPathSegmentPath('some-node', ['workspaceName' => 'user-editor']), $token, ['text' => 'Über uns']);
        self::assertSame(404, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('There is no content repository with the ID unknown', self::json($response)['detail']);
        // the title if there is no text
        self::assertSame(404, $this->post(self::uriPathSegmentPath('some-node', ['workspaceName' => 'user-editor']), $token, [])->getStatusCode());

        // no default workspace for changes
        self::assertSame(400, $this->post(self::uriPathSegmentPath('some-node', []), $token, [])->getStatusCode());
        // its schema rejects it
        self::assertSame(400, $this->post(self::uriPathSegmentPath('some-node', ['workspaceName' => 'user-editor']), $token, ['text' => 42])->getStatusCode());
    }

    #[Test]
    public function generatingUriPathSegmentsRequiresTheScopeAndThePrivilege(): void
    {
        $path = self::uriPathSegmentPath('some-node', ['workspaceName' => 'user-editor']);

        $response = $this->post($path, $this->token('editor-machine', 'nodes.read'), []);
        self::assertSame(403, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringContainsString('nodes.update', self::json($response)['detail']);
        self::assertSame(403, $this->post($path, $this->token('nobody-machine', 'nodes.update'), [])->getStatusCode());
        self::assertSame(401, $this->post($path, null, [])->getStatusCode());
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

        $response = $this->put(self::tagsPath('some-node', $workspace, 'disabled'), $token, []);
        self::assertSame(404, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('There is no content repository with the ID unknown', self::json($response)['detail']);
        self::assertSame(404, $this->delete(self::tagsPath('some-node', $workspace, 'disabled'), $token)->getStatusCode());

        // no default workspace for changes
        self::assertSame(400, $this->put(self::tagsPath('some-node', [], 'disabled'), $token, [])->getStatusCode());
        self::assertSame(400, $this->delete(self::tagsPath('some-node', [], 'disabled'), $token)->getStatusCode());

        // removed is up to deleteNode and the trash
        foreach ([$this->put(self::tagsPath('some-node', $workspace, 'removed'), $token, []), $this->delete(self::tagsPath('some-node', $workspace, 'removed'), $token)] as $response) {
            self::assertSame(422, $response->getStatusCode(), (string)$response->getBody());
        }

        // not a tag, their schemas reject them
        foreach (['Disabled', 'with space', str_repeat('a', 37)] as $tag) {
            self::assertSame(400, $this->put(self::tagsPath('some-node', $workspace, $tag), $token, [])->getStatusCode(), $tag);
            self::assertSame(400, $this->delete(self::tagsPath('some-node', $workspace, $tag), $token)->getStatusCode(), $tag);
        }

        // idempotent like asset tags: PUT on the tag, no POST with a body
        $paths = self::json($this->get('/api/openapi.json', null))['paths'];
        self::assertArrayNotHasKey('/cr/{contentRepositoryId}/nodes/{nodeAggregateId}/tags', $paths);
        self::assertSame(['put', 'delete'], array_keys($paths['/cr/{contentRepositoryId}/nodes/{nodeAggregateId}/tags/{tag}']));
        self::assertSame('tagNode', $paths['/cr/{contentRepositoryId}/nodes/{nodeAggregateId}/tags/{tag}']['put']['operationId']);
        self::assertArrayNotHasKey('requestBody', $paths['/cr/{contentRepositoryId}/nodes/{nodeAggregateId}/tags/{tag}']['put']);
    }

    #[Test]
    public function taggingRequiresTheScopeAndThePrivilege(): void
    {
        $path = self::tagsPath('some-node', ['workspaceName' => 'user-editor'], 'disabled');

        $response = $this->put($path, $this->token('editor-machine', 'nodes.read'), []);
        self::assertSame(403, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringContainsString('nodes.update', self::json($response)['detail']);
        self::assertSame(403, $this->delete($path, $this->token('nobody-machine', 'nodes.update'))->getStatusCode());
        self::assertSame(401, $this->put($path, null, [])->getStatusCode());
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

    #[Test]
    public function movesNodesOnlyInAGivenWorkspace(): void
    {
        $token = $this->token('editor-machine', 'nodes.update');
        $path = self::movePath('some-node', ['workspaceName' => 'user-editor']);

        foreach ([['parentNodeAggregateId' => 'other-node'], ['succeedingSiblingNodeAggregateId' => 'other-node'], ['parentNodeAggregateId' => 'other-node', 'succeedingSiblingNodeAggregateId' => 'third-node']] as $body) {
            $response = $this->post($path, $token, $body);
            self::assertSame(404, $response->getStatusCode(), json_encode($body) . ': ' . $response->getBody());
            self::assertSame('There is no content repository with the ID unknown', self::json($response)['detail']);
        }

        // no default workspace for changes
        self::assertSame(400, $this->post(self::movePath('some-node', []), $token, ['parentNodeAggregateId' => 'other-node'])->getStatusCode());

        $response = $this->post($path, $token, ['nodeName' => 'x']);
        self::assertSame(400, $response->getStatusCode(), (string)$response->getBody());
        $response = $this->post($path, $token, ['parentNodeAggregateId' => null]);
        self::assertSame(400, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('Give parentNodeAggregateId or succeedingSiblingNodeAggregateId, or both', self::json($response)['detail']);
        self::assertSame(400, $this->post($path, $token, ['parentNodeAggregateId' => 'Not_An_Id'])->getStatusCode());
    }

    #[Test]
    public function movingRequiresTheScopeAndThePrivilege(): void
    {
        $path = self::movePath('some-node', ['workspaceName' => 'user-editor']);
        $body = ['parentNodeAggregateId' => 'other-node'];

        $response = $this->post($path, $this->token('editor-machine', 'nodes.read'), $body);
        self::assertSame(403, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringContainsString('nodes.update', self::json($response)['detail']);
        self::assertSame(403, $this->post($path, $this->token('nobody-machine', 'nodes.update'), $body)->getStatusCode());
        self::assertSame(401, $this->post($path, null, $body)->getStatusCode());
    }

    #[Test]
    public function updatesReferencesOnlyInAGivenWorkspace(): void
    {
        $token = $this->token('editor-machine', 'nodes.update');
        $body = ['relatedPages' => [['nodeAggregateId' => 'other-node']], 'teasers' => []];

        $response = $this->patch(self::referencesPath('some-node', ['workspaceName' => 'user-editor']), $token, $body);
        self::assertSame(404, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('There is no content repository with the ID unknown', self::json($response)['detail']);

        // no default workspace for changes
        self::assertSame(400, $this->patch(self::referencesPath('some-node', []), $token, $body)->getStatusCode());
        // the body's schema rejects anything but a map, e.g. a list
        self::assertSame(400, $this->patch(self::referencesPath('some-node', ['workspaceName' => 'user-editor']), $token, [['nodeAggregateId' => 'other-node']])->getStatusCode());
    }

    #[Test]
    public function updatingReferencesRequiresTheScopeAndThePrivilege(): void
    {
        $path = self::referencesPath('some-node', ['workspaceName' => 'user-editor']);
        $body = ['relatedPages' => []];

        $response = $this->patch($path, $this->token('editor-machine', 'nodes.read'), $body);
        self::assertSame(403, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringContainsString('nodes.update', self::json($response)['detail']);
        self::assertSame(403, $this->patch($path, $this->token('nobody-machine', 'nodes.update'), $body)->getStatusCode());
        self::assertSame(401, $this->patch($path, null, $body)->getStatusCode());
    }

    #[Test]
    public function createsVariantsOnlyInAGivenWorkspace(): void
    {
        $token = $this->token('editor-machine', 'nodes.create');
        $path = self::variantsPath('some-node', ['workspaceName' => 'user-editor']);

        $response = $this->post($path, $token, ['dimensionSpacePoint' => '{"language":"de"}', 'copyContent' => true]);
        self::assertSame(404, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('There is no content repository with the ID unknown', self::json($response)['detail']);

        // no default workspace for changes
        self::assertSame(400, $this->post(self::variantsPath('some-node', []), $token, ['dimensionSpacePoint' => '{"language":"de"}'])->getStatusCode());
        // their schemas reject them: no target, not a point
        foreach ([[], ['dimensionSpacePoint' => 'de'], ['dimensionSpacePoint' => '{"language":"de"}', 'copyContent' => 'yes']] as $body) {
            self::assertSame(400, $this->post($path, $token, $body)->getStatusCode(), json_encode($body));
        }
    }

    #[Test]
    public function creatingVariantsRequiresTheScopeAndThePrivilege(): void
    {
        $path = self::variantsPath('some-node', ['workspaceName' => 'user-editor']);
        $body = ['dimensionSpacePoint' => '{"language":"de"}'];

        $response = $this->post($path, $this->token('editor-machine', 'nodes.update'), $body);
        self::assertSame(403, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringContainsString('nodes.create', self::json($response)['detail']);
        self::assertSame(403, $this->post($path, $this->token('nobody-machine', 'nodes.create'), $body)->getStatusCode());
        self::assertSame(401, $this->post($path, null, $body)->getStatusCode());
    }

    #[Test]
    public function listsVariants(): void
    {
        $token = $this->token('nobody-machine', 'nodes.read');

        foreach ([[], ['workspaceName' => 'user-editor', 'include' => 'references,children.references']] as $query) {
            $response = $this->get(self::variantsPath('some-node', $query), $token);
            self::assertSame(404, $response->getStatusCode(), (string)$response->getBody());
            self::assertSame('There is no content repository with the ID unknown', self::json($response)['detail']);
        }
        // variants have no variants
        self::assertSame(400, $this->get(self::variantsPath('some-node', ['include' => 'variants']), $token)->getStatusCode());
        self::assertSame(403, $this->get(self::variantsPath('some-node', []), $this->token('nobody-machine', 'me.read'))->getStatusCode());
        self::assertSame(401, $this->get(self::variantsPath('some-node', []), null)->getStatusCode());
    }

    #[Test]
    public function locationsOfNewNodesAndVariantsAreRelativeToTheirOperation(): void
    {
        $node = new Node(
            ContentRepositoryId::fromString('default'),
            WorkspaceName::fromString('user-editor'),
            DimensionSpacePoint::fromString('{"language":"de"}'),
            NodeAggregateId::fromString('new-node'),
            null,
            NodeTypeName::fromString('Neos.Neos:Page'),
            'New',
            NodeClassification::REGULAR,
            false,
            false,
            false,
            [],
            [],
            new NodeTimestamps('2026-10-01T09:30:00+00:00', null, '2026-10-01T09:30:00+00:00', null),
            [],
            null,
            null,
            null,
            null,
        );
        $query = '?workspaceName=user-editor&dimensionSpacePoint=%7B%22language%22%3A%22de%22%7D';

        $created = NodeCreated::node($node)->headers()['Location'];
        self::assertSame('nodes/new-node' . $query, $created);
        self::assertSame('/api/cr/default/nodes/new-node' . $query, (string)UriResolver::resolve(new Uri('/api/cr/default/nodes?workspaceName=user-editor'), new Uri($created)));

        $variant = NodeCreated::variant($node)->headers()['Location'];
        self::assertSame('../new-node' . $query, $variant);
        self::assertSame('/api/cr/default/nodes/new-node' . $query, (string)UriResolver::resolve(new Uri('/api/cr/default/nodes/new-node/variants?workspaceName=user-editor'), new Uri($variant)));
    }

    #[Test]
    public function writesNodesInAContentRepository(): void
    {
        $this->requireLiveWorkspaceAndAdministrator();
        $siteNodeId = $this->siteNodeId();
        $token = $this->token('admin-machine', 'nodes.read nodes.create nodes.update');
        $inLive = ['workspaceName' => 'live', 'dimensionSpacePoint' => '{"language":"en_US"}'];

        // a title with nothing to transliterate gets the node type's random uriPathSegment
        $response = $this->post(self::path('', $inLive), $token, ['nodeType' => 'Neos.Demo:Document.Page', 'parentNodeAggregateId' => $siteNodeId, 'properties' => ['title' => '!!!']]);
        self::assertSame(201, $response->getStatusCode(), (string)$response->getBody());
        $page = self::json($response);
        $id = $page['nodeAggregateId'];
        self::assertMatchesRegularExpression('/^document-page-\d{3}$/', $page['properties']['uriPathSegment']);
        self::assertSame('nodes/' . $id . '?workspaceName=live&dimensionSpacePoint=%7B%22language%22%3A%22en_US%22%7D', $response->getHeaderLine('Location'));

        // references the node type doesn't declare, before anything is created
        $response = $this->post(self::path('', $inLive), $token, ['nodeType' => 'Neos.Demo:Document.Page', 'parentNodeAggregateId' => $siteNodeId, 'references' => ['unknownReference' => []]]);
        self::assertSame(422, $response->getStatusCode(), (string)$response->getBody());

        $response = $this->get(self::path('', $inLive + ['filterByHierarchy' => ['type' => 'childOf', 'nodeAggregateId' => $siteNodeId]]), $token);
        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertContains($id, array_column(self::json($response)['data'], 'nodeAggregateId'));

        // the body's point is no query parameter: 422s
        foreach (['{not json}', '{"language":"fr"}'] as $point) {
            $response = $this->post(self::path($id . '/variants', $inLive), $token, ['dimensionSpacePoint' => $point]);
            self::assertSame(422, $response->getStatusCode(), $point . ': ' . $response->getBody());
            self::assertStringContainsString('dimensionSpacePoint of the body', self::json($response)['detail']);
        }
        $response = $this->post(self::path($id . '/variants', $inLive), $token, ['dimensionSpacePoint' => '{"language":"de"}']);
        self::assertSame(201, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('../' . $id . '?workspaceName=live&dimensionSpacePoint=%7B%22language%22%3A%22de%22%7D', $response->getHeaderLine('Location'));
        self::assertSame('{"language":"de"}', self::json($response)['dimensionSpacePoint']);

        $response = $this->get(self::path($id . '/variants', ['workspaceName' => 'live']), $token);
        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame(['{"language":"en_US"}', '{"language":"de"}'], array_column(self::json($response)['data'], 'dimensionSpacePoint'));
        $response = $this->get(self::path($id . '/variants', ['workspaceName' => 'unknown-workspace']), $token);
        self::assertSame(404, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('There is no workspace unknown-workspace in the content repository default', self::json($response)['detail']);

        // tagging is idempotent, untagging a tag the node doesn't have a 404
        foreach ([1, 2] as $time) {
            $response = $this->put(self::path($id . '/tags/disabled', $inLive), $token, []);
            self::assertSame(200, $response->getStatusCode(), $time . ': ' . $response->getBody());
            self::assertSame(['disabled'], self::json($response)['tags']);
            self::assertTrue(self::json($response)['isHidden']);
        }
        $response = $this->delete(self::path($id . '/tags/disabled', $inLive), $token);
        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame([], self::json($response)['tags']);
        self::assertSame(404, $this->delete(self::path($id . '/tags/disabled', $inLive), $token)->getStatusCode());
    }

    /**
     * The content repository with live, and an administrator to create the site
     */
    private function requireLiveWorkspaceAndAdministrator(): void
    {
        $this->requireLiveWorkspace();
        $this->objectManager->get(UserService::class)->createUser('admin', 'password', 'Ada', 'Admin', ['Neos.Neos:Administrator']);
        $this->addMachineClient('admin-machine', 'admin');
        $this->persistenceManager->persistAll();
    }

    /**
     * The site node of a new Neos.Demo site in live
     */
    private function siteNodeId(): string
    {
        $response = $this->post('/api/sites', $this->token('admin-machine', 'sites.create'), ['packageKey' => 'Neos.Demo', 'name' => 'Nodes Test', 'nodeTypeName' => 'Neos.Demo:Document.Homepage', 'nodeName' => 'nodes-test']);
        self::assertSame(201, $response->getStatusCode(), (string)$response->getBody());
        $siteNodeId = self::json($response)['nodeAggregateId'];
        self::assertIsString($siteNodeId);
        return $siteNodeId;
    }

    /**
     * @param array<string, mixed> $query
     */
    private static function path(string $below, array $query): string
    {
        return '/api/cr/default/nodes' . ($below !== '' ? '/' . $below : '') . self::query($query);
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
    private static function uriPathSegmentPath(string $nodeAggregateId, array $query): string
    {
        return '/api/cr/unknown/nodes/' . rawurlencode($nodeAggregateId) . '/uripathsegment' . self::query($query);
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
    private static function movePath(string $nodeAggregateId, array $query): string
    {
        return '/api/cr/unknown/nodes/' . rawurlencode($nodeAggregateId) . '/move' . self::query($query);
    }

    /**
     * @param array<string, mixed> $query
     */
    private static function referencesPath(string $nodeAggregateId, array $query): string
    {
        return '/api/cr/unknown/nodes/' . rawurlencode($nodeAggregateId) . '/references' . self::query($query);
    }

    /**
     * @param array<string, mixed> $query
     */
    private static function variantsPath(string $nodeAggregateId, array $query): string
    {
        return '/api/cr/unknown/nodes/' . rawurlencode($nodeAggregateId) . '/variants' . self::query($query);
    }

    /**
     * @param array<string, mixed> $query
     */
    private static function query(array $query): string
    {
        return $query !== [] ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '';
    }
}
