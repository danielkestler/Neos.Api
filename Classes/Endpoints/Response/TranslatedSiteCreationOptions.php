<?php
declare(strict_types=1);

namespace Neos\Api\Endpoints\Response;

use Neos\Api\Endpoints\Model\Site\SiteCreationOptions;
use Neos\Api\I18n\Labels;
use Neos\OpenApi\Binding\TypeReference;

/**
 * The site creation options, with the labels in the requested language
 */
final readonly class TranslatedSiteCreationOptions extends Translated
{
    public function __construct(
        private SiteCreationOptions $body,
        Labels $labels,
    ) {
        parent::__construct($labels);
    }

    public static function description(): string
    {
        return 'The site creation options, with the labels in the requested language';
    }

    public static function bodyType(): TypeReference
    {
        return TypeReference::of(SiteCreationOptions::class);
    }

    public function body(): SiteCreationOptions
    {
        return $this->body;
    }
}
