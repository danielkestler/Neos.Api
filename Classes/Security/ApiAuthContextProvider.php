<?php
declare(strict_types=1);

namespace Neos\Api\Security;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Security\Account;
use Neos\Flow\Security\Authorization\PrivilegeManagerInterface;
use Neos\Flow\Security\Policy\PolicyService;
use Neos\Flow\Security\Policy\Role;
use Neos\OAuth\Domain\ScopeRegistry;
use Neos\OAuth\Security\AuthorizationServerMetadata;
use Neos\OAuth\Security\OAuthContext;
use Neos\OpenApi\FlowAdapter\AuthContextProviderWithSchemes;
use Neos\OpenApi\Spec\OAuthFlowObject;
use Neos\OpenApi\Spec\OAuthFlowsObject;
use Neos\OpenApi\Spec\SecurityRequirementObject;
use Neos\OpenApi\Spec\SecuritySchemeObject;
use Neos\OpenApi\Spec\SecuritySchemeOrReferenceObjectMap;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Bridges Neos.OAuth to neos/openapi: operations declare
 * `security: [self::SCOPES => [ApiScopes::WRITE], self::PRIVILEGES => [ApiPrivileges::USERS_WRITE]]`, this hands them
 * the caller if the request's access token grants those scopes and its account holds those privileges
 */
#[Flow\Scope('singleton')]
final class ApiAuthContextProvider implements AuthContextProviderWithSchemes
{
    /**
     * The name of the OAuth 2 security scheme, named after what operations list for it: the scopes the token must grant
     */
    public const string SCOPES = 'oauth2';

    /**
     * The name of a security scheme that isn't a credential of its own: OpenAPI lets requirements of non-OAuth schemes
     * list role names, which is how the operations declare the privileges the account of the OAuth token must hold
     */
    public const string PRIVILEGES = 'neosPrivileges';

    public function __construct(
        private readonly OAuthContext $oauthContext,
        private readonly ScopeRegistry $scopeRegistry,
        private readonly AuthorizationServerMetadata $authorizationServer,
        private readonly PolicyService $policyService,
        private readonly PrivilegeManagerInterface $privilegeManager,
    ) {
    }

    public function securitySchemes(): SecuritySchemeOrReferenceObjectMap
    {
        $scopes = $this->scopeRegistry->descriptions();
        $tokenUrl = $this->authorizationServer->tokenUrl();
        return SecuritySchemeOrReferenceObjectMap::create()
            ->with(self::SCOPES, SecuritySchemeObject::oauth2(
                new OAuthFlowsObject(
                    clientCredentials: new OAuthFlowObject(scopes: $scopes, tokenUrl: $tokenUrl),
                    authorizationCode: new OAuthFlowObject(scopes: $scopes, authorizationUrl: $this->authorizationServer->authorizationUrl(), tokenUrl: $tokenUrl, refreshUrl: $tokenUrl),
                ),
                description: sprintf(
                    'OAuth 2 access token of a Neos account. The authorization code flow requires PKCE (%s).',
                    implode(', ', $this->authorizationServer->codeChallengeMethods()),
                ),
            ))
            ->with(self::PRIVILEGES, SecuritySchemeObject::bearer(
                description: sprintf('Not a credential of its own, nothing to enter: lists the Neos privileges the account of the "%s" access token must hold, which its roles grant.', self::SCOPES),
            ));
    }

    /**
     * @throws InsufficientScope if the caller is authenticated but the token lacks the scopes
     * @throws MissingPrivileges if the token has the scopes but its account lacks the privileges
     */
    public function authContextFor(ServerRequestInterface $request, SecurityRequirementObject $requirement): ?ApiCaller
    {
        $grant = $this->oauthContext->authenticatedGrant();
        if ($grant === null) {
            return null;
        }
        $requiredScopes = null;
        $missingPrivileges = null;
        foreach ($requirement as $alternative) {
            // anonymous alternatives are handled by neos/openapi, other schemes are unknown to this provider
            if (!isset($alternative[self::SCOPES]) || array_diff(array_keys($alternative), [self::SCOPES, self::PRIVILEGES]) !== []) {
                continue;
            }
            if ($grant->missingScopes(...$alternative[self::SCOPES]) !== []) {
                $requiredScopes ??= $alternative[self::SCOPES];
                continue;
            }
            $roles = $this->rolesOf($grant->account);
            $missing = array_values(array_filter(
                $alternative[self::PRIVILEGES] ?? [],
                fn (string $privilegeTarget) => !$this->privilegeManager->isPrivilegeTargetGrantedForRoles($roles, $privilegeTarget),
            ));
            if ($missing === []) {
                return ApiCaller::fromGrant($grant);
            }
            $missingPrivileges ??= $missing;
        }
        if ($requirement->anonymousAccessAllowed) {
            return null;
        }
        if ($missingPrivileges !== null) {
            throw new MissingPrivileges($missingPrivileges);
        }
        if ($requiredScopes !== null) {
            throw new InsufficientScope($requiredScopes);
        }
        return null;
    }

    /**
     * What Flow's security context would hold for the account alone, i.e. not the roles of other tokens, e.g. of a
     * backend session
     *
     * @return array<string, Role>
     */
    private function rolesOf(Account $account): array
    {
        $roles = [];
        foreach (['Neos.Flow:Everybody', 'Neos.Flow:AuthenticatedUser'] as $identifier) {
            $roles[$identifier] = $this->policyService->getRole($identifier);
        }
        foreach ($account->getRoles() as $role) {
            $roles[$role->getIdentifier()] = $role;
            $roles += $role->getAllParentRoles();
        }
        return $roles;
    }
}
