<?php
declare(strict_types=1);

namespace Neos\Api\Security;

use Neos\Api\Shared\Schema\ClientIdentifier;
use Neos\Api\Shared\Schema\Scope;
use Neos\Api\Shared\Schema\ScopeList;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Security\Account;
use Neos\OAuth\Security\AuthenticatedGrant;

/**
 * Who calls an operation: the #[AuthContext] argument of every secured operation
 *
 * The account's roles decide what the caller may do, the scopes can only narrow that down
 */
#[Flow\Proxy(false)]
final readonly class ApiCaller
{
    private function __construct(
        public Account $account,
        public ClientIdentifier $clientIdentifier,
        public ScopeList $scopes,
    ) {
    }

    public static function fromGrant(AuthenticatedGrant $grant): self
    {
        return new self(
            $grant->account,
            ClientIdentifier::fromString($grant->clientIdentifier),
            ScopeList::fromStrings(...$grant->scopes),
        );
    }

    public function hasScope(Scope $scope): bool
    {
        return $this->scopes->contains($scope);
    }
}
