<?php
declare(strict_types=1);

namespace Neos\Api\Endpoints\Response;

use Neos\Api\Domain\ContentRepository\ContentRepositories;
use Neos\Api\I18n\Labels;
use Neos\OpenApi\Binding\TypeReference;

/**
 * The content repositories, with the labels in the requested language
 */
final readonly class TranslatedContentRepositories extends Translated
{
    public function __construct(
        private ContentRepositories $body,
        Labels $labels,
    ) {
        parent::__construct($labels);
    }

    public static function description(): string
    {
        return 'The content repositories, with the labels in the requested language';
    }

    public static function bodyType(): TypeReference
    {
        return TypeReference::of(ContentRepositories::class);
    }

    public function body(): ContentRepositories
    {
        return $this->body;
    }
}
