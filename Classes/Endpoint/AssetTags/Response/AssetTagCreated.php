<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\AssetTags\Response;

use Neos\Api\Endpoint\AssetTags\Schema\AssetTag;
use Neos\OpenApi\Binding\BuiltinType;
use Neos\OpenApi\Binding\TypeReference;
use Neos\OpenApi\Response\ApiResponseWithHeaders;
use Neos\OpenApi\Response\ResponseHeader;
use Neos\OpenApi\Response\ResponseHeaders;
use Neos\OpenApi\Support\HttpStatusCode;
use Neos\OpenApi\Support\MediaTypeRange;

/**
 * A 201 with the new asset tag and where to find it
 */
final readonly class AssetTagCreated implements ApiResponseWithHeaders
{
    public function __construct(
        private AssetTag $assetTag,
    ) {
    }

    public static function statusCode(): HttpStatusCode
    {
        return HttpStatusCode::fromInteger(201);
    }

    public static function description(): string
    {
        return 'The asset tag was created';
    }

    public static function bodyType(): TypeReference
    {
        return TypeReference::of(AssetTag::class);
    }

    public static function contentType(): MediaTypeRange
    {
        return MediaTypeRange::fromString('application/json');
    }

    public static function headerTypes(): ResponseHeaders
    {
        return ResponseHeaders::create(
            ResponseHeader::create('Location', TypeReference::builtin(BuiltinType::string), description: 'The URI of the new asset tag, relative to the request URI'),
        );
    }

    public function body(): AssetTag
    {
        return $this->assetTag;
    }

    public function headers(): array
    {
        // resolved against POST …/assettags, this is …/assettags/{id}, whatever the API's URI prefix is
        return ['Location' => 'assettags/' . $this->assetTag->id->value];
    }
}
