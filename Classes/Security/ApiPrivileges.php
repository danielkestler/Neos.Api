<?php
declare(strict_types=1);

namespace Neos\Api\Security;

/**
 * The privilege targets operations require of the account, beyond the scopes of the access token:
 * `security: [ApiAuthContextProvider::PRIVILEGES => [ApiPrivileges::USERS_READ], …]`
 *
 * Each one is declared as an ApiPrivilege and granted to roles in Policy.yaml
 */
final class ApiPrivileges
{
    public const string USERS_READ = 'Neos.Api:Users.Read';
    public const string USERS_WRITE = 'Neos.Api:Users.Write';
}
