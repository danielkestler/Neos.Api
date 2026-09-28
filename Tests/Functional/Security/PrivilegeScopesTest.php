<?php
declare(strict_types=1);

namespace Neos\Api\Tests\Functional\Security;

use Neos\Api\Security\ApiScopes;
use Neos\Api\Security\PrivilegeScopes;
use Neos\Flow\Tests\FunctionalTestCase;
use Neos\OAuth\Domain\ScopeRegistry;
use PHPUnit\Framework\Attributes\Test;

/**
 * Scopes and privileges are one list: the ApiPrivilege targets of Policy.yaml
 */
class PrivilegeScopesTest extends FunctionalTestCase
{
    #[Test]
    public function eachApiPrivilegeIsAScope(): void
    {
        $privilegeScopes = $this->objectManager->get(PrivilegeScopes::class);

        self::assertSame([
            'me.read' => 'Read your account',
            'users.read' => 'List and read the Neos users',
            'users.update' => 'Change the Neos users',
            'users.create' => 'Create Neos users',
            'users.delete' => 'Delete Neos users',
        ], $privilegeScopes->scopes());
        self::assertSame('Neos.Api:Users.Update', $privilegeScopes->privilegeTargetOf('users.update'));
    }

    #[Test]
    public function theScopesAreRegisteredWithNeosOAuth(): void
    {
        $scopeRegistry = $this->objectManager->get(ScopeRegistry::class);

        self::assertTrue($scopeRegistry->isKnown('users.update'));
        self::assertSame('Change the Neos users', $scopeRegistry->describe('users.update'));

        $response = $this->browser->request('http://localhost/.well-known/oauth-protected-resource/api');
        self::assertSame(['me.read', 'users.read', 'users.update', 'users.create', 'users.delete'], json_decode((string)$response->getBody(), true, flags: JSON_THROW_ON_ERROR)['scopes_supported']);
    }

    #[Test]
    public function eachApiScopesConstantHasAPrivilege(): void
    {
        $privilegeScopes = $this->objectManager->get(PrivilegeScopes::class);

        foreach ((new \ReflectionClass(ApiScopes::class))->getConstants() as $name => $scope) {
            self::assertNotEmpty($privilegeScopes->privilegeTargetOf($scope), $name);
        }
    }

    #[Test]
    public function eachScopeOfTheOperationsHasAPrivilege(): void
    {
        $document = json_decode((string)$this->browser->request('http://localhost/api/openapi.json')->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $privilegeScopes = $this->objectManager->get(PrivilegeScopes::class);

        $scopes = [];
        foreach ($document['paths'] as $path => $operations) {
            foreach ($operations as $method => $operation) {
                foreach ($operation['security'] ?? [] as $alternative) {
                    foreach ($alternative['oauth2'] ?? [] as $scope) {
                        $scopes[] = $scope;
                        self::assertNotEmpty($privilegeScopes->privilegeTargetOf($scope), $method . ' ' . $path);
                    }
                }
            }
        }
        self::assertNotEmpty($scopes);
    }

    #[Test]
    public function unknownScopesHaveNoPrivilege(): void
    {
        $this->expectExceptionCode(1790200010);
        $this->objectManager->get(PrivilegeScopes::class)->privilegeTargetOf('users.purge');
    }
}
