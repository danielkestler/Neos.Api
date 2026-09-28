<?php
declare(strict_types=1);

namespace Neos\Api\Controller;

use GuzzleHttp\Psr7\Response;
use Neos\Api\Security\PrivilegeScopes;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Controller\ActionController;
use Neos\OpenApi\FlowAdapter\CompiledApis;
use Psr\Http\Message\ResponseInterface;

/**
 * Swagger UI for the API, where users log in with their Neos account (authorization code flow with PKCE)
 */
class DocsController extends ActionController
{
    public const string API_NAME = 'neos';

    #[Flow\Inject]
    protected CompiledApis $compiledApis;

    #[Flow\Inject]
    protected PrivilegeScopes $privilegeScopes;

    /**
     * @var array{clientId: string, swaggerUiVersion: string}
     */
    #[Flow\InjectConfiguration(path: 'docs', package: 'Neos.Api')]
    protected array $docsSettings;

    public function indexAction(): void
    {
        $this->view->assignMultiple([
            'swaggerUiVersion' => $this->docsSettings['swaggerUiVersion'],
            'config' => json_encode([
                'specUrl' => '/' . $this->compiledApis->uriPrefix(self::API_NAME) . '/' . $this->compiledApis->specPath(self::API_NAME),
                // absolute, but from the browser's location rather than the request's Host header
                'redirectPath' => $this->uriBuilder->reset()->uriFor('oauth2Redirect'),
                'clientId' => $this->docsSettings['clientId'],
                'scopes' => array_keys($this->privilegeScopes->scopes()),
            ], JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES),
        ]);
    }

    /**
     * Where the login popup returns to: hands the authorization code back to the Swagger UI window. It has to be on
     * the same origin as the docs
     */
    public function oauth2RedirectAction(): ResponseInterface
    {
        $script = sprintf('https://cdn.jsdelivr.net/npm/swagger-ui-dist@%s/oauth2-redirect.js', rawurlencode($this->docsSettings['swaggerUiVersion']));
        return new Response(200, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer'], sprintf(
            "<!doctype html>\n<html lang=\"en\">\n<body>\n<script src=\"%s\"></script>\n</body>\n</html>\n",
            htmlspecialchars($script),
        ));
    }
}
