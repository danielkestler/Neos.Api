<?php
declare(strict_types=1);

namespace Neos\Api\Infrastructure\Fusion;

use Neos\Fusion\FusionObjects\AbstractFusionObject;

/**
 * Renders the absolute Fusion path, as the Neos UI renders a content element after a change: with the path the element
 * was rendered at (its data-__neos-fusion-path attribute in an edit mode), so it gets the prototypes and settings that
 * apply there, e.g. a wrapper its container adds to its children. The context is the one of the object, node is the
 * element
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

    public function evaluate(): mixed
    {
        $path = $this->fusionValue('path');
        if (!is_string($path) || $path === '') {
            throw new InvalidViewArgument('A Fusion path to render is needed', 1791802331);
        }
        if (!preg_match(self::PATTERN, $path)) {
            throw new InvalidViewArgument(sprintf('"%s" is no Fusion path', $path), 1791802332);
        }
        $path = (string)preg_replace(self::CONTENT_CASE_ELEMENT, '$1', $path);
        if (!$this->runtime->canRender($path)) {
            throw new InvalidViewArgument(sprintf('There is nothing to render at the Fusion path %s', $path), 1791802333);
        }
        return $this->runtime->render($path);
    }
}
