<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\Views;

use Neos\Api\Endpoint\Nodes\Schema\NodeAddress;
use Neos\Api\Endpoint\Views\Response\RenderedView;
use Neos\Api\Endpoint\Views\Schema\RenderingModeName;
use Neos\Api\Endpoint\Views\Schema\View;
use Neos\Api\Endpoint\Views\Schema\ViewName;
use Neos\Api\Endpoint\Views\Schema\ViewList;
use Neos\Api\Infrastructure\ContentRepository\ContentSubgraphs;
use Neos\Api\Infrastructure\Fusion\ViewRenderer;
use Neos\Api\Security\AccountPrivileges;
use Neos\Api\Security\ApiAuthContextProvider;
use Neos\Api\Security\ApiCaller;
use Neos\Api\Security\ApiScopes;
use Neos\Api\Shared\Response\BadRequest;
use Neos\Api\Shared\Response\Forbidden;
use Neos\Api\Shared\Response\NotFound;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindClosestNodeFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\SharedModel;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\Model\RenderingMode;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Neos\Domain\Service\RenderingModeService;
use Neos\Neos\Domain\Service\NodeTypeNameFactory;
use Neos\OpenApi\Attributes\AuthContext;
use Neos\OpenApi\Attributes\Operation;
use Neos\OpenApi\Attributes\Parameter;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Views of nodes: data Fusion prototypes build, e.g. a navigation with grouping folders or external data
 *
 * A view is a prototype inheriting from Neos.Api:View, made available under a name in the Neos.Api.views settings.
 * It is rendered with the Fusion of the node's site and the same context as in the frontend: node, documentNode, site
 */
final readonly class Views
{
    /**
     * Neos grants the rendering modes other than frontend only to accounts with it, see
     * RenderingModeService::findByCurrentUser()
     */
    private const string BACKEND_ACCESS = 'Neos.Neos:Backend.GeneralAccess';

    /**
     * @var array<string, array{prototype: string, description?: string|null}>
     */
    private array $views;

    /**
     * @var list<string>
     */
    private array $renderingModeNames;

    /**
     * @param array<string, array{prototype: string, description?: string|null}|null> $views
     * @param array<string, mixed> $editPreviewModes
     */
    public function __construct(
        private ContentSubgraphs $contentSubgraphs,
        private SiteRepository $siteRepository,
        private RenderingModeService $renderingModeService,
        private AccountPrivileges $accountPrivileges,
        private ViewRenderer $viewRenderer,
        #[Flow\InjectConfiguration(path: 'views')]
        array $views,
        #[Flow\InjectConfiguration(path: 'userInterface.editPreviewModes', package: 'Neos.Neos')]
        array $editPreviewModes,
    ) {
        // a view or rendering mode can be removed by setting it to null
        $this->views = array_filter($views, static fn (array|null $view) => $view !== null);
        $this->renderingModeNames = [RenderingMode::FRONTEND, ...array_map('strval', array_keys(array_filter($editPreviewModes, static fn (mixed $mode) => $mode !== null)))];
    }

    #[Operation(
        path: '/views',
        method: 'GET',
        summary: 'List all views',
        description: 'The views in the order of the Neos.Api.views settings. Each site renders a view with its own Fusion, a site may not have the prototype of every view.',
        operationId: 'listViews',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::VIEWS_READ],
        ],
    )]
    public function list(): ViewList
    {
        $views = [];
        foreach ($this->views as $name => $view) {
            $views[] = new View(ViewName::fromString((string)$name), $view['description'] ?? null);
        }
        return new ViewList(...$views);
    }

    #[Operation(
        path: '/views/{viewName}',
        method: 'GET',
        summary: 'Render a view',
        description: 'Renders the view\'s Fusion prototype for a node with the Fusion of the node\'s site. Without a node, it is rendered for the site node of the default site (Neos.Neos.defaultSiteNodeName, else the first online site) in the live workspace and the site\'s default dimension space point, as the frontend renders its home page. In the frontend rendering mode and the live workspace, disabled nodes are left out as in the frontend, otherwise they are visible to accounts that may see them, as in the preview of the Neos backend. Rendering modes other than frontend need the privilege Neos.Neos:Backend.GeneralAccess, as in Neos. The shape of the data is up to the view.',
        operationId: 'renderView',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::VIEWS_READ],
        ],
    )]
    public function render(
        ViewName $viewName,
        ServerRequestInterface $request,
        #[AuthContext] ApiCaller $caller,
        #[Parameter(in: 'query', description: 'The address of the node to render the view for, URL-encoded. The site node of the default site if omitted')]
        NodeAddress|null $nodeAddress = null,
        #[Parameter(in: 'query', description: 'The rendering mode, the Fusion global renderingMode. frontend if omitted, the Neos UI uses e.g. inPlace')]
        RenderingModeName|null $renderingMode = null,
    ): RenderedView|NotFound|BadRequest|Forbidden {
        $view = $this->views[$viewName->value] ?? null;
        if ($view === null) {
            return NotFound::because(sprintf('There is no view %s', $viewName->value));
        }
        $mode = $this->renderingMode($renderingMode);
        if ($mode === null) {
            return BadRequest::because(sprintf('There is no rendering mode %s, there are: %s', $renderingMode?->value, implode(', ', $this->renderingModeNames)));
        }
        // the one rule the operation's security can't express: it depends on an argument
        if ($mode->name !== RenderingMode::FRONTEND && !$this->accountPrivileges->isGranted($caller->account, self::BACKEND_ACCESS)) {
            return Forbidden::because(sprintf('The rendering mode %s needs the privilege %s', $mode->name, self::BACKEND_ACCESS));
        }
        if ($nodeAddress === null) {
            $nodes = $this->defaultSiteNode($mode);
        } else {
            try {
                $nodes = $this->addressedNode($nodeAddress->toNodeAddress(), $mode);
            } catch (\InvalidArgumentException | \TypeError $exception) {
                return BadRequest::because(sprintf('The node address is invalid: %s', $exception->getMessage()));
            }
        }
        if ($nodes instanceof NotFound) {
            return $nodes;
        }
        [$contextNode, $site] = $nodes;
        $data = $this->viewRenderer->render($contextNode, $site, $view['prototype'], $mode, $request);
        if ($data === null) {
            return NotFound::because(sprintf('The site of the node has no Fusion prototype %s for the view %s', $view['prototype'], $viewName->value));
        }
        return new RenderedView($data);
    }

    /**
     * @return array{Node, Node}|NotFound the node and its site node
     */
    private function addressedNode(SharedModel\Node\NodeAddress $address, RenderingMode $renderingMode): array|NotFound
    {
        $subgraph = $this->subgraph($address->contentRepositoryId, $address->workspaceName, $address->dimensionSpacePoint, $renderingMode);
        $node = $subgraph?->findNodeById($address->aggregateId);
        if ($subgraph === null || $node === null) {
            return NotFound::because(sprintf('There is no node %s', $address->toJson()));
        }
        $site = $subgraph->findClosestNode($address->aggregateId, FindClosestNodeFilter::create(nodeTypes: NodeTypeNameFactory::NAME_SITE));
        if ($site === null) {
            return NotFound::because(sprintf('The node %s belongs to no site, so there is no Fusion to render it with', $address->toJson()));
        }
        return [$node, $site];
    }

    /**
     * The site node of the default site in the live workspace and the site's default dimension space point, which
     * the frontend renders for the home page
     *
     * @return array{Node, Node}|NotFound the site node twice, as the node and its site node
     */
    private function defaultSiteNode(RenderingMode $renderingMode): array|NotFound
    {
        $site = $this->siteRepository->findDefault();
        if ($site === null) {
            return NotFound::because('There is no site to render the view for, give a nodeAddress');
        }
        $configuration = $site->getConfiguration();
        $subgraph = $this->subgraph($configuration->contentRepositoryId, WorkspaceName::forLive(), $configuration->defaultDimensionSpacePoint, $renderingMode);
        $sitesNode = $subgraph?->findRootNodeByType(NodeTypeNameFactory::forSites());
        $siteNode = $sitesNode !== null ? $subgraph?->findNodeByPath($site->getNodeName()->toNodeName(), $sitesNode->aggregateId) : null;
        if ($siteNode === null) {
            return NotFound::because(sprintf(
                'The default site %s has no site node in the live workspace and its default dimension space point %s, configure Neos.Neos.sites.*.contentDimensions.defaultDimensionSpacePoint or give a nodeAddress',
                $site->getNodeName()->value,
                $configuration->defaultDimensionSpacePoint->toJson(),
            ));
        }
        return [$siteNode, $siteNode];
    }

    /**
     * The subgraph as the frontend (frontend rendering mode in live: never with disabled nodes) or the preview of the
     * Neos backend (otherwise) sees it, null if there is none the account may read
     */
    private function subgraph(SharedModel\ContentRepository\ContentRepositoryId $contentRepositoryId, WorkspaceName $workspaceName, DimensionSpacePoint $dimensionSpacePoint, RenderingMode $renderingMode): ?ContentSubgraphInterface
    {
        return $this->contentSubgraphs->find($contentRepositoryId, $workspaceName, $dimensionSpacePoint, $workspaceName->isLive() && $renderingMode->name === RenderingMode::FRONTEND);
    }

    /**
     * The rendering mode of the name, frontend without one, null if there is none of the name
     */
    private function renderingMode(?RenderingModeName $name): ?RenderingMode
    {
        if ($name === null) {
            return RenderingMode::createFrontend();
        }
        // checked up front: the service throws for an unknown one
        return in_array($name->value, $this->renderingModeNames, true) ? $this->renderingModeService->findByName($name->value) : null;
    }
}
