<?php
declare(strict_types=1);

namespace Neos\Api\Endpoints;

use Neos\Api\Domain\User;
use Neos\Api\Domain\UserId;
use Neos\Api\Domain\Users as UserList;
use Neos\Api\Endpoints\Model\UserPatch;
use Neos\Api\Endpoints\Model\UsersResponse;
use Neos\Api\Endpoints\Response\NotFound;
use Neos\Api\Security\ApiAuthContextProvider;
use Neos\Api\Security\ApiScopes;
use Neos\Neos\Domain\Model\User as NeosUser;
use Neos\Neos\Domain\Model\UserId as NeosUserId;
use Neos\Neos\Domain\Service\UserService;
use Neos\OpenApi\Attributes\Operation;
use Neos\OpenApi\Attributes\RequestBody;

/**
 * The Neos users
 */
final readonly class Users
{
    public function __construct(
        private UserService $userService,
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
    public function list(): UsersResponse
    {
        return new UsersResponse(new UserList(...array_map(
            User::fromNeosUser(...),
            $this->userService->getUsers()->toArray(),
        )));
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
            ApiAuthContextProvider::SCOPES => [ApiScopes::USERS_WRITE],
        ],
    )]
    public function update(
        UserId $userId,
        #[RequestBody(description: 'The properties to change')] UserPatch $patch,
    ): User|NotFound {
        $user = $this->findUser($userId);
        if ($user === null) {
            return self::notFound($userId);
        }
        $patch->applyTo($user);
        $this->userService->updateUser($user);
        return User::fromNeosUser($user);
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
