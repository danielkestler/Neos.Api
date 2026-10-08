<?php
declare(strict_types=1);

namespace Neos\Api\Infrastructure\ContentRepository;

use Behat\Transliterator\Transliterator;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\I18n\Exception\InvalidLocaleIdentifierException;
use Neos\Flow\I18n\Locale;
use Neos\Neos\Service\TransliterationService;

/**
 * The uriPathSegment of a new document, as the Neos backend gives it one: its title transliterated in the language of
 * its dimension space point, else <node type suffix>-<random number> like blog-022. Not unique among its siblings,
 * the backend's isn't either
 *
 * Neos UI's UriPathSegmentNodeCreationHandlerFactory does it there, which is @internal
 */
#[Flow\Scope('singleton')]
final readonly class UriPathSegments
{
    public function __construct(
        private TransliterationService $transliterationService,
    ) {
    }

    public function forNewDocument(NodeTypeName $nodeTypeName, ?string $title, DimensionSpacePoint $dimensionSpacePoint): string
    {
        if ($title === null || $title === '') {
            $nodeTypeSuffix = explode(':', $nodeTypeName->value)[1] ?? '';
            return Transliterator::urlize(sprintf('%s-%03d', $nodeTypeSuffix, random_int(0, 999)));
        }
        return Transliterator::urlize($this->transliterationService->transliterate($title, $this->language($dimensionSpacePoint)));
    }

    private function language(DimensionSpacePoint $dimensionSpacePoint): ?string
    {
        $languageDimensionValue = $dimensionSpacePoint->coordinates['language'] ?? null;
        if ($languageDimensionValue === null) {
            return null;
        }
        try {
            return (new Locale($languageDimensionValue))->getLanguage();
        } catch (InvalidLocaleIdentifierException) {
            return null;
        }
    }
}
