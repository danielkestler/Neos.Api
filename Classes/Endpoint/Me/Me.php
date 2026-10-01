<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\Me;

use Neos\Api\Endpoint\Me\Schema\CurrentAccount;
use Neos\Api\Endpoint\Users\Schema\Account;
use Neos\Api\Endpoint\Users\Schema\User;
use Neos\Api\Security\ApiAuthContextProvider;
use Neos\Api\Security\ApiCaller;
use Neos\Api\Security\ApiScopes;
use Neos\Api\Shared\Schema\AccessTokenGrant;
use Neos\Neos\Domain\Model;
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
    public function get(#[AuthContext] ApiCaller $caller): CurrentAccount
    {
        // not UserService::getUser(), which fails for accounts without a party
        $user = $this->partyService->getAssignedPartyOfAccount($caller->account);
        return new CurrentAccount(
            Account::from($caller->account),
            $user instanceof Model\User ? User::from($user) : null,
            new AccessTokenGrant($caller->clientIdentifier, $caller->scopes),
        );
    }
}
