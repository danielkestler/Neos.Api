<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\Users\Response;

use Neos\Api\Endpoint\Users\Schema\User;
use Neos\OpenApi\Binding\BuiltinType;
use Neos\OpenApi\Binding\TypeReference;
use Neos\OpenApi\Response\ApiResponseWithHeaders;
use Neos\OpenApi\Response\ResponseHeader;
use Neos\OpenApi\Response\ResponseHeaders;
use Neos\OpenApi\Support\HttpStatusCode;
use Neos\OpenApi\Support\MediaTypeRange;

/**
 * A 201 with the new user and where to find it
 */
final readonly class UserCreated implements ApiResponseWithHeaders
{
    public function __construct(
        private User $user,
    ) {
    }

    public static function statusCode(): HttpStatusCode
    {
        return HttpStatusCode::fromInteger(201);
    }

    public static function description(): string
    {
        return 'The user was created';
    }

    public static function bodyType(): TypeReference
    {
        return TypeReference::of(User::class);
    }

    public static function contentType(): MediaTypeRange
    {
        return MediaTypeRange::fromString('application/json');
    }

    public static function headerTypes(): ResponseHeaders
    {
        return ResponseHeaders::create(
            ResponseHeader::create('Location', TypeReference::builtin(BuiltinType::string), description: 'The URI of the new user, relative to the request URI'),
        );
    }

    public function body(): User
    {
        return $this->user;
    }

    public function headers(): array
    {
        // resolved against POST …/users, this is …/users/{userId}, whatever the API's URI prefix is
        return ['Location' => 'users/' . $this->user->id->value];
    }
}
