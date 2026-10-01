<?php
declare(strict_types=1);

namespace Neos\Api\Infrastructure\Fusion;

use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\Fusion\Core\ExceptionHandlers\ThrowingHandler;
use Neos\Neos\View\FusionView;

/**
 * Neos' FusionView, so a view gets the same globals (request, renderingMode), context (node, documentNode, site) and
 * locale as in the frontend, but Fusion errors are thrown instead of being rendered into the output
 *
 * @internal used by ViewRenderer
 */
class ViewFusionView extends FusionView
{
    protected function getFusionRuntime(Node $currentSiteNode)
    {
        $fusionRuntime = parent::getFusionRuntime($currentSiteNode);
        $fusionRuntime->overrideExceptionHandler(new ThrowingHandler());
        return $fusionRuntime;
    }
}
