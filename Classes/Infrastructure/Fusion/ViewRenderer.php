<?php
declare(strict_types=1);

namespace Neos\Api\Infrastructure\Fusion;

use Neos\Api\Infrastructure\Json\JsonValues;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\ActionRequest;
use Neos\Neos\Domain\Model\RenderingMode;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Renders a Fusion prototype for a node in a rendering mode and decodes the JSON it renders
 *
 * The prototype is rendered on the path neosApiView<Prototype.Name>, it needs no root path. Inheriting from
 * Neos.Api:View makes it a data structure that is encoded to JSON
 */
#[Flow\Scope('singleton')]
final readonly class ViewRenderer
{
    public const string FUSION_PATH = 'neosApiView';

    /**
     * The node carries the content repository, workspace and dimension space point it was found in, so it decides
     * them for the whole rendering: the locale comes from its language dimension, and every node Fusion finds from
     * it is in the same subgraph
     *
     * @param Node $node the context variable node, documentNode is its closest document
     * @param Node $site the site node of the node, from the same subgraph, whose site's Fusion is used
     * @param string $prototypeName the prototype to render, e.g. one inheriting from Neos.Api:View
     * @param RenderingMode $renderingMode the global renderingMode, e.g. frontend or an edit mode
     * @param ServerRequestInterface $httpRequest the API request, Fusion builds URIs with it
     * @return array<mixed>|null the data, null if the site has no such prototype
     */
    public function render(Node $node, Node $site, string $prototypeName, RenderingMode $renderingMode, ServerRequestInterface $httpRequest): ?array
    {
        $view = new ViewFusionView();
        $view->setOption('renderingModeName', $renderingMode->name);
        $view->assign('request', ActionRequest::fromHttpRequest($httpRequest));
        $view->assign('value', $node);
        $view->assign('site', $site);
        $view->setFusionPath(sprintf('%s<%s>', self::FUSION_PATH, $prototypeName));
        if (!$view->canRenderWithNodeAndPath()) {
            return null;
        }
        $output = $view->render();
        $json = trim((string)($output instanceof ResponseInterface ? $output->getBody() : $output));
        // an empty Neos.Fusion:DataStructure is encoded as []
        if ($json === '[]') {
            return [];
        }
        $data = str_starts_with($json, '{') ? json_decode($json) : null;
        if (!$data instanceof \stdClass) {
            throw new \RuntimeException(sprintf('The Fusion prototype %s must render a JSON object, e.g. by inheriting from Neos.Api:View', $prototypeName), 1790838157);
        }
        return array_map(JsonValues::decoded(...), get_object_vars($data));
    }
}
