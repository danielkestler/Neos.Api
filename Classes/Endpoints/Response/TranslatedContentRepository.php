<?php
declare(strict_types=1);

namespace Neos\Api\Endpoints\Response;

use Neos\Api\Domain\ContentRepository\ContentRepository;
use Neos\Api\I18n\Labels;
use Neos\OpenApi\Binding\TypeReference;

/**
 * The content repository, with the labels in the requested language
 */
final readonly class TranslatedContentRepository extends Translated
{
    public function __construct(
        private ContentRepository $body,
        Labels $labels,
    ) {
        parent::__construct($labels);
    }

    public static function description(): string
    {
        return 'The content repository, with the labels in the requested language';
    }

    public static function bodyType(): TypeReference
    {
        return TypeReference::of(ContentRepository::class);
    }

    public function body(): ContentRepository
    {
        return $this->body;
    }
}
