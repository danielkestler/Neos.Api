<?php
declare(strict_types=1);

namespace Neos\Api\Infrastructure\Http;

use Neos\Api\Infrastructure\I18n\LabelTranslator;
use Neos\Flow\Annotations as Flow;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Keeps the API's responses out of shared caches, and says what they depend on
 *
 * - `Cache-Control: private, no-store` and `Vary: Authorization`: a response depends on the token's account and scopes
 * - `Content-Language` and `Vary: Accept-Language` if the operation translated labels, see LabelTranslator
 *
 * Not for the docs and the OpenAPI document, which are public and the same for everybody. It runs around
 * OAuthChallengeMiddleware, whose 401 and 403 responses get the headers as well.
 */
final class ApiCacheHeadersMiddleware implements MiddlewareInterface
{
    #[Flow\Inject]
    protected LabelTranslator $labelTranslator;

    #[Flow\InjectConfiguration(path: 'apis.neos', package: 'Neos.OpenApi.FlowAdapter')]
    protected array $api;

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->isOperation($request)) {
            return $handler->handle($request);
        }
        $this->labelTranslator->forget();
        $response = $handler->handle($request);
        if (!$response->hasHeader('Cache-Control')) {
            $response = $response->withHeader('Cache-Control', 'private, no-store');
        }
        $response = $response->withAddedHeader('Vary', 'Authorization');
        $locale = $this->labelTranslator->usedLocale();
        if ($locale !== null) {
            $response = $response
                // Flow's locale identifiers separate the region with an underscore, language tags with a hyphen
                ->withHeader('Content-Language', str_replace('_', '-', (string)$locale))
                ->withAddedHeader('Vary', 'Accept-Language');
        }
        return $response;
    }

    private function isOperation(ServerRequestInterface $request): bool
    {
        $prefix = '/' . trim($this->api['uriPrefix'], '/') . '/';
        $path = $request->getUri()->getPath();
        if (!str_starts_with($path, $prefix)) {
            return false;
        }
        $subPath = substr($path, strlen($prefix));
        // the DocsController's routes, see Routes.yaml
        return $subPath !== ($this->api['specPath'] ?? null) && $subPath !== 'docs' && !str_starts_with($subPath, 'docs/');
    }
}
