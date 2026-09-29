<?php
declare(strict_types=1);

namespace Neos\Api\Tests\Functional\Feature;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Neos\Neos\Domain\Service\UserService;
use PHPUnit\Framework\Attributes\Test;

class ContentRepositoriesTest extends EndpointTestCase
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
    public function listsTheContentRepositoriesForEditors(): void
    {
        $this->requireContentRepository();
        $response = $this->get('/api/contentrepositories', $this->token('editor-machine', 'contentrepositories.read'));

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        $contentRepositories = self::json($response);
        self::assertContains('default', array_column($contentRepositories, 'id'));
        foreach ($contentRepositories as $contentRepository) {
            self::assertIsArray($contentRepository['dimensions']);
        }
    }

    #[Test]
    public function getsAContentRepository(): void
    {
        $this->requireContentRepository();
        $response = $this->get('/api/contentrepositories/default', $this->token('editor-machine', 'contentrepositories.read'));

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('default', self::json($response)['id']);

        $response = $this->get('/api/contentrepositories/default', $this->token('editor-machine', 'contentrepositories.read'), ['Accept-Language' => 'de']);
        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('de', $response->getHeaderLine('Content-Language'));
        self::assertSame(['Authorization', 'Accept-Language'], $response->getHeader('Vary'));
    }

    #[Test]
    public function unknownContentRepositoriesAreNotFound(): void
    {
        $response = $this->get('/api/contentrepositories/unknown', $this->token('editor-machine', 'contentrepositories.read'));

        self::assertSame(404, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
        // with the header, which is an optional parameter
        self::assertSame(404, $this->get('/api/contentrepositories/unknown', $this->token('editor-machine', 'contentrepositories.read'), ['Accept-Language' => 'de'])->getStatusCode());
    }

    #[Test]
    public function rejectsInvalidIds(): void
    {
        self::assertSame(400, $this->get('/api/contentrepositories/Not-An-Id', $this->token('editor-machine', 'contentrepositories.read'))->getStatusCode());
    }

    #[Test]
    public function isDeniedToAccountsWithoutThePrivilege(): void
    {
        $response = $this->get('/api/contentrepositories', $this->token('nobody-machine', 'contentrepositories.read'));

        self::assertSame(403, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringContainsString('Neos.Api:ContentRepositories.Read', self::json($response)['detail']);
    }

    #[Test]
    public function requiresTheScope(): void
    {
        $response = $this->get('/api/contentrepositories', $this->token('editor-machine', 'me.read'));

        self::assertSame(403, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringContainsString('contentrepositories.read', self::json($response)['detail']);
    }

    #[Test]
    public function requiresAToken(): void
    {
        self::assertSame(401, $this->get('/api/contentrepositories', null)->getStatusCode());
    }

    private function requireContentRepository(): void
    {
        if (!$this->objectManager->get(EntityManagerInterface::class)->getConnection()->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            self::markTestSkipped('The content repository needs a MariaDB or MySQL database');
        }
    }
}
