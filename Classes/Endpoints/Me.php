<?php
declare(strict_types=1);

namespace Neos\Api\Endpoints;

use Neos\Api\Domain\Account;
use Neos\Api\Domain\User;
use Neos\Api\Endpoints\Model\AccessTokenGrant;
use Neos\Api\Endpoints\Model\ClientIdentifier;
use Neos\Api\Endpoints\Model\MeResponse;
use Neos\Api\Endpoints\Model\Scope;
use Neos\Api\Endpoints\Model\Scopes;
use Neos\Api\Security\ApiAuthContextProvider;
use Neos\Api\Security\ApiCaller;
use Neos\Api\Security\ApiScopes;
use Neos\Neos\Domain\Model\User as NeosUser;
use Neos\OpenApi\Attributes\AuthContext;
use Neos\OpenApi\Attributes\Operation;
use Neos\Party\Domain\Service\PartyService;

/**
 * The account of the access token
 */
final readonly class Me
{
    public function __construct(
        private PartyService $partyService,
    ) {
    }

    #[Operation(
        path: '/me',
        method: 'GET',
        summary: 'Get the current account',
        description: 'The account the access token acts as with its roles, the Neos user it belongs to, and the client and scopes of the token.',
        operationId: 'getMe',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::ME_READ],
        ],
    )]
    public function get(#[AuthContext] ApiCaller $caller): MeResponse
    {
        // not UserService::getUser(), which fails for accounts without a party
        $user = $this->partyService->getAssignedPartyOfAccount($caller->account);
        return new MeResponse(
            Account::fromFlowAccount($caller->account),
            $user instanceof NeosUser ? User::fromNeosUser($user) : null,
            new AccessTokenGrant(
                ClientIdentifier::fromString($caller->clientIdentifier),
                new Scopes(...array_map(Scope::fromString(...), $caller->scopes)),
            ),
        );
    }
}
