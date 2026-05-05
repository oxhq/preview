<?php

declare(strict_types=1);

namespace Oxhq\Preview\Http;

use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class RoutePreviewController
{
    public function __construct(
        private readonly Router $router,
        private readonly UrlGenerator $url,
    ) {
    }

    public function __invoke(Request $request, string $route): Response
    {
        abort_unless($this->url->hasValidSignature($request), 403);
        abort_unless($this->router->getRoutes()->getByName($route) !== null, 404);

        $method = $this->method($request);

        abort_if(! in_array($method, ['GET', 'HEAD'], true) && $request->query('_preview_allow_write') !== '1', 403);

        $target = Request::create(route($route, $this->parameters($request), false), $method);
        $target->attributes->set('preview.route', $route);
        $target->attributes->set('preview.readonly_db', $request->query('_preview_readonly_db') === '1');
        $target->attributes->set('preview.guard', $request->query('_preview_guard'));
        $target->attributes->set('preview.fakes', $this->fakes($request));

        $this->applyFakes($this->fakes($request));

        if ($request->query('_preview_readonly_db') === '1') {
            return $this->insideRolledBackTransaction(fn (): Response => $this->router->dispatch($target));
        }

        return $this->router->dispatch($target);
    }

    private function method(Request $request): string
    {
        $method = $request->query('_preview_method');

        return is_string($method) && $method !== '' ? strtoupper($method) : 'GET';
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

    /**
     * @return list<string>
     */
    private function fakes(Request $request): array
    {
        $fakes = $request->query('_preview_fakes');

        if (! is_string($fakes) || trim($fakes) === '') {
            return [];
        }

        return array_values(array_intersect(
            ['queue', 'mail', 'http', 'events'],
            array_map('trim', explode(',', strtolower($fakes))),
        ));
    }

    /**
     * @param list<string> $fakes
     */
    private function applyFakes(array $fakes): void
    {
        foreach ($fakes as $fake) {
            try {
                match ($fake) {
                    'queue' => class_exists(Queue::class) ? Queue::fake() : null,
                    'mail' => class_exists(Mail::class) ? Mail::fake() : null,
                    'http' => class_exists(Http::class) ? Http::fake() : null,
                    'events' => class_exists(Event::class) ? Event::fake() : null,
                    default => null,
                };
            } catch (Throwable) {
                // Missing optional Laravel bindings should not break preview execution.
            }
        }
    }

    /**
     * @param callable(): Response $callback
     */
    private function insideRolledBackTransaction(callable $callback): Response
    {
        DB::beginTransaction();

        try {
            return $callback();
        } finally {
            DB::rollBack();
        }
    }
}
