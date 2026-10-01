<?php
declare(strict_types=1);

namespace Neos\Api\Tests\Functional\Endpoint;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Neos\Neos\Domain\Service\UserService;
use PHPUnit\Framework\Attributes\Test;

class NodeTypesTest extends EndpointTestCase
{
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
    public function listsTheNodeTypesWithoutTheirConfiguration(): void
    {
        $this->requireContentRepository();
        $response = $this->get('/api/nodetypes', $this->token('editor-machine', 'nodetypes.read'));

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        $nodeTypes = array_column(self::json($response), null, 'name');
        $names = array_keys($nodeTypes);
        $sorted = $names;
        sort($sorted);
        self::assertSame($sorted, $names);

        $document = $nodeTypes['Neos.Neos:Document'];
        self::assertTrue($document['isAbstract']);
        self::assertNull($document['configuration']);
        self::assertContains('Neos.Neos:Node', $nodeTypes['Neos.Neos:Document']['superTypes']);
    }

    #[Test]
    public function getsANodeTypeWithItsConfiguration(): void
    {
        $this->requireContentRepository();
        $response = $this->get('/api/nodetypes/Neos.Neos:Document', $this->token('editor-machine', 'nodetypes.read'), ['Accept-Language' => 'de']);

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        $nodeType = self::json($response);
        self::assertSame('Neos.Neos:Document', $nodeType['name']);
        self::assertArrayHasKey('title', $nodeType['configuration']['properties']);
        self::assertSame('de', $response->getHeaderLine('Content-Language'));
    }

    #[Test]
    public function unknownNodeTypesAreNotFound(): void
    {
        $this->requireContentRepository();
        $response = $this->get('/api/nodetypes/Vendor.Unknown:Type', $this->token('editor-machine', 'nodetypes.read'));

        self::assertSame(404, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function unknownContentRepositoriesAreNotFound(): void
    {
        self::assertSame(404, $this->get('/api/nodetypes?contentRepositoryId=unknown', $this->token('editor-machine', 'nodetypes.read'))->getStatusCode());
        self::assertSame(404, $this->get('/api/nodetypes/Neos.Neos:Document?contentRepositoryId=unknown', $this->token('editor-machine', 'nodetypes.read'))->getStatusCode());
    }

    #[Test]
    public function rejectsInvalidNames(): void
    {
        self::assertSame(400, $this->get('/api/nodetypes/NoColon', $this->token('editor-machine', 'nodetypes.read'))->getStatusCode());
        self::assertSame(400, $this->get('/api/nodetypes?contentRepositoryId=Not-An-Id', $this->token('editor-machine', 'nodetypes.read'))->getStatusCode());
    }

    #[Test]
    public function isDeniedToAccountsWithoutThePrivilege(): void
    {
        $response = $this->get('/api/nodetypes', $this->token('nobody-machine', 'nodetypes.read'));

        self::assertSame(403, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringContainsString('Neos.Api:NodeTypes.Read', self::json($response)['detail']);
    }

    #[Test]
    public function requiresTheScope(): void
    {
        $response = $this->get('/api/nodetypes', $this->token('editor-machine', 'me.read'));

        self::assertSame(403, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringContainsString('nodetypes.read', self::json($response)['detail']);
    }

    #[Test]
    public function requiresAToken(): void
    {
        self::assertSame(401, $this->get('/api/nodetypes', null)->getStatusCode());
    }

    private function requireContentRepository(): void
    {
        if (!$this->objectManager->get(EntityManagerInterface::class)->getConnection()->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            self::markTestSkipped('The content repository needs a MariaDB or MySQL database');
        }
    }
}
