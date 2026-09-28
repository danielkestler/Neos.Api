<?php
declare(strict_types=1);

namespace Neos\Api\Endpoints\Response;

use Neos\OpenApi\Binding\TypeReference;
use Neos\OpenApi\Problem\ProblemDocument;
use Neos\OpenApi\Response\ApiResponse;
use Neos\OpenApi\Support\HttpStatusCode;
use Neos\OpenApi\Support\MediaTypeRange;

/**
 * The request is well-formed but refers to something that doesn't exist, e.g. an unknown role
 */
final readonly class UnprocessableContent implements ApiResponse
{
    private function __construct(
        private ProblemDocument $problem,
    ) {
    }

    public static function because(string $detail): self
    {
        return new self(ProblemDocument::create(self::statusCode(), 'Unprocessable content', $detail));
    }

    public static function statusCode(): HttpStatusCode
    {
        return HttpStatusCode::fromInteger(422);
    }

    public static function description(): string
    {
        return 'The request refers to something that doesn\'t exist';
    }

    public static function bodyType(): TypeReference
    {
        return TypeReference::of(ProblemDocument::class);
    }

    public static function contentType(): MediaTypeRange
    {
        return ProblemDocument::contentType();
    }

    public function body(): ProblemDocument
    {
        return $this->problem;
    }
}
