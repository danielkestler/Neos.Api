<?php
declare(strict_types=1);

namespace Neos\Api\Security;

/**
 * The OAuth scopes of the API, to be used in the operations' `security` requirements
 *
 * Each one is registered with its description in Neos.OAuth.scopes, see Settings.yaml
 */
final class ApiScopes
{
    public const string READ = 'neos.read';
    public const string WRITE = 'neos.write';
}
