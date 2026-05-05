<?php

declare(strict_types=1);

namespace Oxhq\Preview\Http;

use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
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
        $target->attributes->set('preview.session', $this->session($request));
        $target->attributes->set('preview.fakes', $this->fakes($request));
        $target->setLaravelSession($this->sessionStore($request));

        $this->applyFakes($this->fakes($request));

        if ($request->query('_preview_readonly_db') === '1') {
            return $this->insideRolledBackTransaction(fn (): Response => $this->dispatchTarget($request, $target));
        }

        return $this->dispatchTarget($request, $target);
    }

    private function dispatchTarget(Request $original, Request $target): Response
    {
        if (! function_exists('app')) {
            return $this->router->dispatch($target);
        }

        $sessionSnapshot = $this->seedSessionManager($target);

        app()->instance('request', $target);

        try {
            return $this->router->dispatch($target);
        } finally {
            app()->instance('request', $original);
            $this->restoreSessionManager($sessionSnapshot);
        }
    }

    /**
     * @return array{store: mixed, values: array<string, array{exists: bool, value: mixed}>}|null
     */
    private function seedSessionManager(Request $target): ?array
    {
        if (! app()->bound('session')) {
            return null;
        }

        $session = $target->attributes->get('preview.session', []);

        if (! is_array($session) || $session === []) {
            return null;
        }

        try {
            $store = app('session')->driver();
        } catch (Throwable) {
            return null;
        }

        $snapshot = [];

        foreach ($session as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

            $snapshot[$key] = [
                'exists' => $store->exists($key),
                'value' => $store->get($key),
            ];

            $store->put($key, $value);
        }

        return [
            'store' => $store,
            'values' => $snapshot,
        ];
    }

    /**
     * @param array{store: mixed, values: array<string, array{exists: bool, value: mixed}>}|null $snapshot
     */
    private function restoreSessionManager(?array $snapshot): void
    {
        if ($snapshot === null) {
            return;
        }

        $store = $snapshot['store'];

        foreach ($snapshot['values'] as $key => $state) {
            if ($state['exists']) {
                $store->put($key, $state['value']);

                continue;
            }

            $store->forget($key);
        }
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
        return $this->decodeContext($request, '_preview_params');
    }

    /**
     * @return array<string, mixed>
     */
    private function session(Request $request): array
    {
        return $this->decodeContext($request, '_preview_session');
    }

    private function sessionStore(Request $request): Store
    {
        $session = new Store('preview-route', new ArraySessionHandler(3600));
        $session->start();

        foreach ($this->session($request) as $key => $value) {
            $session->put($key, $value);
        }

        return $session;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeContext(Request $request, string $key): array
    {
        $encoded = $request->query($key);

        if (! is_string($encoded) || $encoded === '') {
            return [];
        }

        $base64 = strtr($encoded, '-_', '+/');
        $base64 .= str_repeat('=', (4 - strlen($base64) % 4) % 4);
        $decoded = base64_decode($base64, true);
        $parameters = $decoded === false ? null : json_decode($decoded, true);

        if (! is_array($parameters)) {
            throw new RuntimeException('Preview route context could not be decoded.');
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
