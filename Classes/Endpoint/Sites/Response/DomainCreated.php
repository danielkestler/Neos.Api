<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\Sites\Response;

use Neos\Api\Endpoint\Sites\Schema\Site;
use Neos\OpenApi\Binding\TypeReference;
use Neos\OpenApi\Response\ApiResponse;
use Neos\OpenApi\Support\HttpStatusCode;
use Neos\OpenApi\Support\MediaTypeRange;

/**
 * A 201 with the site the new domain was added to
 */
final readonly class DomainCreated implements ApiResponse
{
    public function __construct(
        private Site $site,
    ) {
    }

    public static function statusCode(): HttpStatusCode
    {
        return HttpStatusCode::fromInteger(201);
    }

    public static function description(): string
    {
        return 'The domain was added, the body is the site with it';
    }

    public static function bodyType(): TypeReference
    {
        return TypeReference::of(Site::class);
    }

    public static function contentType(): MediaTypeRange
    {
        return MediaTypeRange::fromString('application/json');
    }

    public function body(): Site
    {
        return $this->site;
    }
}
