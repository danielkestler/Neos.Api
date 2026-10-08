<?php
declare(strict_types=1);

namespace Neos\Api\Tests\Functional\Controller;

use Neos\Flow\Tests\FunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;

class DocsControllerTest extends FunctionalTestCase
{
    #[Test]
    public function docsLogInWithTheDocsClientAndPkce(): void
    {
        $response = $this->browser->request('http://localhost/api/docs');

        self::assertSame(200, $response->getStatusCode());
        $html = (string)$response->getBody();
        self::assertStringContainsString('"clientId":"neos-api-docs"', $html);
        self::assertStringContainsString('"specUrl":"/api/openapi.json"', $html);
        self::assertStringContainsString('"redirectPath":"/api/docs/oauth2-redirect"', $html);
        self::assertStringContainsString('"scopes":["me.read","users.read","users.update","users.create","users.delete","sites.read","sites.update","sites.create","sites.delete","contentrepositories.read","nodetypes.read","views.read","nodes.read","nodes.update","workspaces.read","datasources.read"]', $html);
        self::assertStringContainsString('usePkceWithAuthorizationCodeGrant: true', $html);
    }

    #[Test]
    public function servesTheOAuthRedirectPage(): void
    {
        $response = $this->browser->request('http://localhost/api/docs/oauth2-redirect');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('oauth2-redirect.js', (string)$response->getBody());
    }
}
