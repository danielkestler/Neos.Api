<?php
declare(strict_types=1);

namespace Neos\Api\Infrastructure\Fusion;

use Neos\Fusion\FusionObjects\AbstractFusionObject;
use Neos\Neos\Domain\Model\RenderingMode;

/**
 * Renders the absolute Fusion path, as the Neos UI renders a content element after a change: with the path the element
 * was rendered at (its data-__neos-fusion-path attribute in an edit mode), so it gets the prototypes and settings that
 * apply there, e.g. a wrapper its container adds to its children. The context is the one of the object, node is the
 * element
 *
 * Only such paths are rendered, not any path of the site's Fusion: below the path Neos renders a page at (root, or the
 * rendering mode's own one, e.g. rawContent), without __meta, and only in a rendering mode other than frontend, which
 * are the ones with these attributes and need Neos.Neos:Backend.GeneralAccess (checked by renderView)
 *
 * A path that ends with the element a Neos.Neos:ContentCase chose is rendered from the case, so a change that makes it
 * choose another one shows, as in the Neos UI's RenderedNodeDomAddress::getFusionPathForContentRendering(). Others,
 * e.g. a content collection's, render as given
 *
 * @internal the implementation of Neos.Api:FusionPath
 */
class FusionPathImplementation extends AbstractFusionObject
{
    /**
     * Path segments, prototype names in angle brackets and the characters of their names
     */
    private const string PATTERN = '#^[a-zA-Z0-9_/<>.:@\\\\-]+$#';

    /**
     * The element a content case chose, at the end of a path
     */
    private const string CONTENT_CASE_ELEMENT = '#(/itemRenderer<Neos\.Neos:ContentCase>)/[^<>/]+<Neos\.Fusion:Matcher>/element<[^>]+>$#';

    /**
     * Where Neos' FusionView starts rendering a page
     */
    private const string PAGE_PATH = 'root';

    public function evaluate(): mixed
    {
        $renderingMode = $this->runtime->fusionGlobals->get('renderingMode');
        if (!$renderingMode instanceof RenderingMode || $renderingMode->name === RenderingMode::FRONTEND) {
            throw new InvalidViewArgument('A Fusion path is only rendered in a rendering mode other than frontend, e.g. inPlace, whose content elements have it', 1791802334);
        }
        $path = $this->fusionValue('path');
        if (!is_string($path) || $path === '') {
            throw new InvalidViewArgument('A Fusion path to render is needed', 1791802331);
        }
        if (!preg_match(self::PATTERN, $path)) {
            throw new InvalidViewArgument(sprintf('"%s" is no Fusion path', $path), 1791802332);
        }
        if (!self::isContentPath($path, $renderingMode)) {
            throw new InvalidViewArgument(sprintf('The Fusion path %s is no path of content on the page, it must start at %s', $path, implode(' or ', self::pagePaths($renderingMode))), 1791802335);
        }
        $path = (string)preg_replace(self::CONTENT_CASE_ELEMENT, '$1', $path);
        if (!$this->runtime->canRender($path)) {
            throw new InvalidViewArgument(sprintf('There is nothing to render at the Fusion path %s', $path), 1791802333);
        }
        return $this->runtime->render($path);
    }

    /**
     * Whether the path is below one Neos renders a page at in the rendering mode, as the paths of content elements are,
     * and has no empty or __meta segments, which they never have
     */
    private static function isContentPath(string $path, RenderingMode $renderingMode): bool
    {
        // the segment names, without their prototypes
        $names = array_map(static fn (string $segment) => (string)preg_replace('#<[^<>]*>$#', '', $segment), explode('/', $path));
        return count($names) > 1
            && in_array($names[0], self::pagePaths($renderingMode), true)
            && !in_array('', $names, true)
            && !in_array('__meta', $names, true);
    }

    /**
     * root, and the rendering mode's own path, which Neos' root case renders instead, e.g. rawContent
     *
     * @return list<string>
     */
    private static function pagePaths(RenderingMode $renderingMode): array
    {
        return array_values(array_unique(array_filter([self::PAGE_PATH, $renderingMode->fusionPath], static fn (string $path) => $path !== '')));
    }
}
