<?php
declare(strict_types=1);

namespace Neos\Api\Endpoints\Response;

use Neos\Api\I18n\Labels;
use Neos\OpenApi\Binding\BuiltinType;
use Neos\OpenApi\Binding\TypeReference;
use Neos\OpenApi\Response\ApiResponseWithHeaders;
use Neos\OpenApi\Response\ResponseHeader;
use Neos\OpenApi\Response\ResponseHeaders;
use Neos\OpenApi\Support\HttpStatusCode;
use Neos\OpenApi\Support\MediaTypeRange;

/**
 * A 200 whose labels are translated to the Accept-Language, which it says for caches and clients
 */
abstract readonly class Translated implements ApiResponseWithHeaders
{
    protected function __construct(
        private Labels $labels,
    ) {
    }

    public static function statusCode(): HttpStatusCode
    {
        return HttpStatusCode::fromInteger(200);
    }

    public static function contentType(): MediaTypeRange
    {
        return MediaTypeRange::fromString('application/json');
    }

    public static function headerTypes(): ResponseHeaders
    {
        return ResponseHeaders::create(
            ResponseHeader::create('Content-Language', TypeReference::builtin(BuiltinType::string), description: 'The language the labels are translated to, e.g. de'),
            ResponseHeader::create('Vary', TypeReference::builtin(BuiltinType::string), description: 'Accept-Language, the labels depend on it'),
        );
    }

    public function headers(): array
    {
        return [
            // Flow's locale identifiers separate the region with an underscore, language tags with a hyphen
            'Content-Language' => str_replace('_', '-', (string)$this->labels->locale),
            'Vary' => 'Accept-Language',
        ];
    }
}
