<?php
declare(strict_types=1);

namespace Neos\Api\Feature\Users;

use Neos\Api\Feature\Users\Payload\UserCreate;
use Neos\Api\Feature\Users\Payload\UserUpdate;
use Neos\Api\Feature\Users\Schema\User;
use Neos\Api\Feature\Users\Schema\UserId;
use Neos\Api\Feature\Users\Schema\Users as UserList;
use Neos\Api\Feature\Users\Response\UserCreated;
use Neos\Api\Security\ApiAuthContextProvider;
use Neos\Api\Security\ApiCaller;
use Neos\Api\Security\ApiScopes;
use Neos\Api\Shared\Response\Conflict;
use Neos\Api\Shared\Response\NotFound;
use Neos\Api\Shared\Response\UnprocessableContent;
use Neos\Flow\Security\Policy\PolicyService;
use Neos\Neos\Domain\Model\User as NeosUser;
use Neos\Neos\Domain\Model\UserId as NeosUserId;
use Neos\Neos\Domain\Service\UserService;
use Neos\OpenApi\Attributes\AuthContext;
use Neos\OpenApi\Attributes\Operation;
use Neos\OpenApi\Attributes\RequestBody;

/**
 * The Neos users
 */
final readonly class Users
{
    public function __construct(
        private UserService $userService,
        private PolicyService $policyService,
    ) {
    }

    #[Operation(
        path: '/users',
        method: 'GET',
        summary: 'List all users',
        description: 'All Neos users with their accounts, ordered by account identifier.',
        operationId: 'listUsers',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::USERS_READ],
        ],
    )]
    public function list(): UserList
    {
        return new UserList(...array_map(
            User::fromNeosUser(...),
            $this->userService->getUsers()->toArray(),
        ));
    }

    #[Operation(
        path: '/users',
        method: 'POST',
        summary: 'Create a user',
        description: 'Creates a Neos user with a backend account.',
        operationId: 'createUser',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::USERS_CREATE],
        ],
    )]
    public function create(
        #[RequestBody(description: 'The user and its account')] UserCreate $newUser,
    ): UserCreated|Conflict|UnprocessableContent {
        if ($this->userService->getUser($newUser->username->value) !== null) {
            return Conflict::because(sprintf('There is an account with the username %s already', $newUser->username->value));
        }
        foreach ($newUser->roles ?? [] as $role) {
            // Flow can't assign abstract roles to accounts
            if (!$this->policyService->hasRole($role->value) || $this->policyService->getRole($role->value)->isAbstract()) {
                return UnprocessableContent::because(sprintf('There is no role %s that can be assigned to an account', $role->value));
            }
        }
        $user = $this->userService->addUser(
            $newUser->username->value,
            $newUser->password->value,
            $newUser->toNeosUser(),
            $newUser->roleIdentifiers(),
        );
        return new UserCreated(User::fromNeosUser($user));
    }

    #[Operation(
        path: '/users/{userId}',
        method: 'GET',
        summary: 'Get a user',
        operationId: 'getUser',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::USERS_READ],
        ],
    )]
    public function get(UserId $userId): User|NotFound
    {
        $user = $this->findUser($userId);
        return $user !== null ? User::fromNeosUser($user) : self::notFound($userId);
    }

    #[Operation(
        path: '/users/{userId}',
        method: 'PATCH',
        summary: 'Change a user',
        description: 'Changes the given properties and leaves the others as they are.',
        operationId: 'updateUser',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::USERS_UPDATE],
        ],
    )]
    public function update(
        UserId $userId,
        #[RequestBody(description: 'The properties to change')] UserUpdate $patch,
    ): User|NotFound {
        $user = $this->findUser($userId);
        if ($user === null) {
            return self::notFound($userId);
        }
        $patch->applyTo($user);
        $this->userService->updateUser($user);
        return User::fromNeosUser($user);
    }

    #[Operation(
        path: '/users/{userId}',
        method: 'DELETE',
        summary: 'Delete a user',
        description: 'Deletes the user with its accounts and personal workspaces, including their unpublished changes, and ends its sessions. You can\'t delete your own user.',
        operationId: 'deleteUser',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::USERS_DELETE],
        ],
    )]
    public function delete(#[AuthContext] ApiCaller $caller, UserId $userId): NotFound|Conflict|null
    {
        $user = $this->findUser($userId);
        if ($user === null) {
            return self::notFound($userId);
        }
        if ($user->getAccounts()->contains($caller->account)) {
            return Conflict::because('You can\'t delete your own user');
        }
        $this->userService->deleteUser($user);
        return null;
    }

    private function findUser(UserId $userId): ?NeosUser
    {
        // the party repository finds any party, not only Neos users
        $user = $this->userService->findUserById(NeosUserId::fromString($userId->value));
        return $user instanceof NeosUser ? $user : null;
    }

    private static function notFound(UserId $userId): NotFound
    {
        return NotFound::because(sprintf('There is no user with the ID %s', $userId->value));
    }
}
