<?php
declare(strict_types=1);

namespace Neos\Api\Security;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Security\Account;
use Neos\Flow\Security\Authorization\PrivilegeManagerInterface;
use Neos\Flow\Security\Policy\PolicyService;
use Neos\Flow\Security\Policy\Role;

/**
 * Whether the account of an access token holds a privilege, from its own roles only
 *
 * Not Flow's security context, which may hold the roles of other tokens as well, e.g. of a backend session
 */
#[Flow\Scope('singleton')]
final readonly class AccountPrivileges
{
    public function __construct(
        private PolicyService $policyService,
        private PrivilegeManagerInterface $privilegeManager,
    ) {
    }

    public function isGranted(Account $account, string $privilegeTarget): bool
    {
        return $this->privilegeManager->isPrivilegeTargetGrantedForRoles($this->rolesOf($account), $privilegeTarget);
    }

    /**
     * What Flow's security context would hold for the account alone
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
