<?php

declare(strict_types=1);

namespace Oxhq\Preview\Http;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Routing\UrlGenerator;
use RuntimeException;

final class RoutePreviewController
{
    public function __construct(
        private readonly Router $router,
        private readonly UrlGenerator $url,
    ) {
    }

    public function __invoke(Request $request, string $route): RedirectResponse
    {
        abort_unless($this->url->hasValidSignature($request), 403);
        abort_unless($this->router->getRoutes()->getByName($route) !== null, 404);

        return redirect()->to(route($route, $this->parameters($request)));
    }

    /**
     * @return array<string, mixed>
     */
    private function parameters(Request $request): array
    {
        $encoded = $request->query('_preview_params');

        if (! is_string($encoded) || $encoded === '') {
            return [];
        }

        $base64 = strtr($encoded, '-_', '+/');
        $base64 .= str_repeat('=', (4 - strlen($base64) % 4) % 4);
        $decoded = base64_decode($base64, true);
        $parameters = $decoded === false ? null : json_decode($decoded, true);

        if (! is_array($parameters)) {
            throw new RuntimeException('Preview route parameters could not be decoded.');
        }

        return $parameters;
    }
}
