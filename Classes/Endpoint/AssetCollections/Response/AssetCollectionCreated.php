<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\AssetCollections\Response;

use Neos\Api\Endpoint\AssetCollections\Schema\AssetCollection;
use Neos\OpenApi\Binding\BuiltinType;
use Neos\OpenApi\Binding\TypeReference;
use Neos\OpenApi\Response\ApiResponseWithHeaders;
use Neos\OpenApi\Response\ResponseHeader;
use Neos\OpenApi\Response\ResponseHeaders;
use Neos\OpenApi\Support\HttpStatusCode;
use Neos\OpenApi\Support\MediaTypeRange;

/**
 * A 201 with the new asset collection and where to find it
 */
final readonly class AssetCollectionCreated implements ApiResponseWithHeaders
{
    public function __construct(
        private AssetCollection $assetCollection,
    ) {
    }

    public static function statusCode(): HttpStatusCode
    {
        return HttpStatusCode::fromInteger(201);
    }

    public static function description(): string
    {
        return 'The asset collection was created';
    }

    public static function bodyType(): TypeReference
    {
        return TypeReference::of(AssetCollection::class);
    }

    public static function contentType(): MediaTypeRange
    {
        return MediaTypeRange::fromString('application/json');
    }

    public static function headerTypes(): ResponseHeaders
    {
        return ResponseHeaders::create(
            ResponseHeader::create('Location', TypeReference::builtin(BuiltinType::string), description: 'The URI of the new asset collection, relative to the request URI'),
        );
    }

    public function body(): AssetCollection
    {
        return $this->assetCollection;
    }

    public function headers(): array
    {
        // resolved against POST …/assetcollections, this is …/assetcollections/{id}, whatever the API's URI prefix is
        return ['Location' => 'assetcollections/' . $this->assetCollection->id->value];
    }
}
