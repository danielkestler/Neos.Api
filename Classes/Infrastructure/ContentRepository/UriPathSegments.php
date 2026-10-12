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
 * The uriPathSegment of a document, as the Neos backend gives it one: a text, usually its title, transliterated in the
 * language of its dimension space point. A new document without a title gets <node type suffix>-<random number> like
 * blog-022. Not unique among its siblings, the backend's isn't either
 *
 * Neos UI's UriPathSegmentNodeCreationHandlerFactory does it there on creation, its "sync" button with
 * NodeUriPathSegmentGenerator, which takes the language of the node's origin instead of the requested point
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
        return $this->fromText($title, $dimensionSpacePoint);
    }

    public function fromText(string $text, DimensionSpacePoint $dimensionSpacePoint): string
    {
        return Transliterator::urlize($this->transliterationService->transliterate($text, $this->language($dimensionSpacePoint)));
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
