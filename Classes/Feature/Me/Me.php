<?php
declare(strict_types=1);

namespace Neos\Api\Feature\Me;

use Neos\Api\Feature\Me\Model\AccessTokenGrant;
use Neos\Api\Feature\Me\Model\ClientIdentifier;
use Neos\Api\Feature\Me\Model\CurrentAccount;
use Neos\Api\Feature\Me\Model\Scope;
use Neos\Api\Feature\Me\Model\Scopes;
use Neos\Api\Feature\Users\Model\Account;
use Neos\Api\Feature\Users\Model\User;
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
    public function get(#[AuthContext] ApiCaller $caller): CurrentAccount
    {
        // not UserService::getUser(), which fails for accounts without a party
        $user = $this->partyService->getAssignedPartyOfAccount($caller->account);
        return new CurrentAccount(
            Account::fromFlowAccount($caller->account),
            $user instanceof NeosUser ? User::fromNeosUser($user) : null,
            new AccessTokenGrant(
                ClientIdentifier::fromString($caller->clientIdentifier),
                new Scopes(...array_map(Scope::fromString(...), $caller->scopes)),
            ),
        );
    }
}
