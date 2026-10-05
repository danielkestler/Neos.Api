<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\Users;

use Neos\Api\Endpoint\Users\Payload\UserCreate;
use Neos\Api\Endpoint\Users\Payload\UserUpdate;
use Neos\Api\Endpoint\Users\Schema\User;
use Neos\Api\Endpoint\Users\Schema\UserId;
use Neos\Api\Endpoint\Users\Schema\UserList;
use Neos\Api\Endpoint\Users\Schema\UserListing;
use Neos\Api\Endpoint\Users\Response\UserCreated;
use Neos\Api\Security\ApiAuthContextProvider;
use Neos\Api\Security\ApiCaller;
use Neos\Api\Security\ApiScopes;
use Neos\Api\Shared\Params;
use Neos\Api\Shared\Response\Conflict;
use Neos\Api\Shared\Response\NotFound;
use Neos\Api\Shared\Response\UnprocessableContent;
use Neos\Api\Shared\Schema\ListingLinks;
use Neos\Api\Shared\Schema\ListingMeta;
use Neos\Flow\Security\Policy\PolicyService;
use Neos\Neos\Domain\Model;
use Neos\Neos\Domain\Service\UserService;
use Neos\OpenApi\Attributes\AuthContext;
use Neos\OpenApi\Attributes\Operation;
use Neos\OpenApi\Attributes\Parameter;
use Neos\OpenApi\Attributes\RequestBody;
use Psr\Http\Message\ServerRequestInterface;

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
        summary: 'List the users',
        description: 'A page of the Neos users with their accounts, ordered by account identifier. page[offset] and page[limit] (25 by default, 100 at most) choose the page, meta.total and links tell about the others.',
        operationId: 'listUsers',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::USERS_READ],
        ],
    )]
    public function list(
        ServerRequestInterface $request,
        #[Parameter(in: 'query', description: 'Which page: page[offset] and page[limit]')]
        Params\Page|null $page = null,
    ): UserListing {
        $page ??= new Params\Page();
        $users = $this->userService->getUsers();
        $total = $users->count();
        // a copy of the query, paged in the database
        $query = $users->getQuery()->setOffset($page->offset)->setLimit($page->limit);
        return new UserListing(
            new UserList(...array_map(User::from(...), $query->execute()->toArray())),
            new ListingMeta($total),
            ListingLinks::for($request, $page, $total),
        );
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
        $user = new Model\User();
        $newUser->applyTo($user);
        $this->userService->addUser(
            $newUser->username->value,
            $newUser->password->value,
            $user,
            $newUser->roleIdentifiers(),
        );
        return new UserCreated(User::from($user));
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
        return $user !== null ? User::from($user) : self::notFound($userId);
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
        return User::from($user);
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

    private function findUser(UserId $userId): ?Model\User
    {
        // the party repository finds any party, not only Neos users
        $user = $this->userService->findUserById(Model\UserId::fromString($userId->value));
        return $user instanceof Model\User ? $user : null;
    }

    private static function notFound(UserId $userId): NotFound
    {
        return NotFound::because(sprintf('There is no user with the ID %s', $userId->value));
    }
}
