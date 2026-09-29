<?php
declare(strict_types=1);

namespace Neos\Api\Endpoints\Response;

use Neos\Api\Domain\Site\Site;
use Neos\OpenApi\Binding\BuiltinType;
use Neos\OpenApi\Binding\TypeReference;
use Neos\OpenApi\Response\ApiResponseWithHeaders;
use Neos\OpenApi\Response\ResponseHeader;
use Neos\OpenApi\Response\ResponseHeaders;
use Neos\OpenApi\Support\HttpStatusCode;
use Neos\OpenApi\Support\MediaTypeRange;

/**
 * A 201 with the new site and where to find it
 */
final readonly class SiteCreated implements ApiResponseWithHeaders
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
        return 'The site was created';
    }

    public static function bodyType(): TypeReference
    {
        return TypeReference::of(Site::class);
    }

    public static function contentType(): MediaTypeRange
    {
        return MediaTypeRange::fromString('application/json');
    }

    public static function headerTypes(): ResponseHeaders
    {
        return ResponseHeaders::create(
            ResponseHeader::create('Location', TypeReference::builtin(BuiltinType::string), description: 'The URI of the new site, relative to the request URI'),
        );
    }

    public function body(): Site
    {
        return $this->site;
    }

    public function headers(): array
    {
        // resolved against POST …/sites, this is …/sites/{siteNodeName}, whatever the API's URI prefix is
        return ['Location' => 'sites/' . $this->site->nodeName->value];
    }
}
