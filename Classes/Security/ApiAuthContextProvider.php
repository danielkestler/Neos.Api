<?php
declare(strict_types=1);

namespace Neos\Api\Security;

use Neos\Flow\Annotations as Flow;
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
 * Bridges Neos.OAuth to neos/openapi: operations declare `security: [self::SCHEME => [ApiScopes::READ]]`, this
 * hands them the caller if the request's access token grants those scopes
 */
#[Flow\Scope('singleton')]
final class ApiAuthContextProvider implements AuthContextProviderWithSchemes
{
    public const string SCHEME = 'oauth2';

    public function __construct(
        private readonly OAuthContext $oauthContext,
        private readonly ScopeRegistry $scopeRegistry,
        private readonly AuthorizationServerMetadata $authorizationServer,
    ) {
    }

    public function securitySchemes(): SecuritySchemeOrReferenceObjectMap
    {
        $scopes = $this->scopeRegistry->descriptions();
        $tokenUrl = $this->authorizationServer->tokenUrl();
        return SecuritySchemeOrReferenceObjectMap::create()->with(self::SCHEME, SecuritySchemeObject::oauth2(
            new OAuthFlowsObject(
                clientCredentials: new OAuthFlowObject(scopes: $scopes, tokenUrl: $tokenUrl),
                authorizationCode: new OAuthFlowObject(scopes: $scopes, authorizationUrl: $this->authorizationServer->authorizationUrl(), tokenUrl: $tokenUrl, refreshUrl: $tokenUrl),
            ),
            description: sprintf(
                'OAuth 2 access token of a Neos account. The authorization code flow requires PKCE (%s).',
                implode(', ', $this->authorizationServer->codeChallengeMethods()),
            ),
        ));
    }

    /**
     * @throws InsufficientScope if the caller is authenticated but lacks the scopes
     */
    public function authContextFor(ServerRequestInterface $request, SecurityRequirementObject $requirement): ?ApiCaller
    {
        $grant = $this->oauthContext->authenticatedGrant();
        if ($grant === null) {
            return null;
        }
        $requiredScopes = null;
        foreach ($requirement as $alternative) {
            // anonymous alternatives are handled by neos/openapi, other schemes are unknown to this provider
            if ($alternative === [] || array_keys($alternative) !== [self::SCHEME]) {
                continue;
            }
            if ($grant->missingScopes(...$alternative[self::SCHEME]) === []) {
                return ApiCaller::fromGrant($grant);
            }
            $requiredScopes ??= $alternative[self::SCHEME];
        }
        if ($requiredScopes !== null && !$requirement->anonymousAccessAllowed) {
            throw new InsufficientScope($requiredScopes);
        }
        return null;
    }
}
