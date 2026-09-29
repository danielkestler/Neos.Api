<?php
declare(strict_types=1);

namespace Neos\Api\I18n;

use Neos\Api\Endpoints\Model\AcceptLanguage;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\I18n\Exception\InvalidLocaleIdentifierException;
use Neos\Flow\I18n\Locale;
use Neos\Flow\I18n\Service as LocalizationService;
use Neos\Flow\I18n\Translator;
use Neos\Flow\I18n\Utility;

/**
 * Translates the labels of the Neos configuration to the language of a request
 */
#[Flow\Scope('singleton')]
final readonly class LabelTranslator
{
    public function __construct(
        private LocalizationService $localizationService,
        private Translator $translator,
    ) {
    }

    /**
     * The labels in the best matching language of the header, in the default locale without one
     */
    public function forAcceptLanguage(AcceptLanguage|null $acceptLanguage): Labels
    {
        $defaultLocale = $this->localizationService->getConfiguration()->getDefaultLocale();
        return new Labels(
            $this->translator,
            ($acceptLanguage !== null ? $this->bestMatchingLocale($acceptLanguage) : null) ?? $defaultLocale,
            $defaultLocale,
        );
    }

    /**
     * Like Detector::detectLocaleFromHttpHeader(), whose locale collection is empty once the service's comes from the cache
     */
    private function bestMatchingLocale(AcceptLanguage $acceptLanguage): Locale|null
    {
        // ordered by quality, false if there is no valid language
        foreach (Utility::parseAcceptLanguageHeader($acceptLanguage->value) ?: [] as $languageTag) {
            if ($languageTag === '*') {
                return null;
            }
            try {
                $locale = $this->localizationService->findBestMatchingLocale(new Locale($languageTag));
            } catch (InvalidLocaleIdentifierException) {
                continue;
            }
            if ($locale !== null) {
                return $locale;
            }
        }
        return null;
    }
}
