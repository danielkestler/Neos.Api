<?php
declare(strict_types=1);

namespace Neos\Api\Security;

/**
 * The OAuth scopes of the API, to be used in the operations' `security` requirements
 *
 * Each one is the matcher of an ApiPrivilege target in Policy.yaml, which the account must hold as well, see
 * PrivilegeScopes
 */
final class ApiScopes
{
    public const string ME_READ = 'me.read';
    public const string USERS_READ = 'users.read';
    public const string USERS_UPDATE = 'users.update';
    public const string USERS_CREATE = 'users.create';
    public const string USERS_DELETE = 'users.delete';
}
