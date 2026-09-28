<?php
declare(strict_types=1);

namespace Neos\Api\Security;

use Neos\Flow\Security\Authorization\Privilege\AbstractPrivilege;
use Neos\Flow\Security\Authorization\Privilege\PrivilegeSubjectInterface;

/**
 * A capability of the API that roles are granted or denied, e.g. listing users
 *
 * The ApiAuthContextProvider checks it for the caller's roles by its privilege target identifier, see ApiPrivileges. It guards no
 * method or entity, so there is nothing to match: the matcher only says in Policy.yaml what the privilege allows
 */
class ApiPrivilege extends AbstractPrivilege
{
    public function matchesSubject(PrivilegeSubjectInterface $subject): bool
    {
        return false;
    }
}
