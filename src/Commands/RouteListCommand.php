<?php

declare(strict_types=1);

namespace Oxhq\Preview\Commands;

use Illuminate\Console\Command;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;

final class RouteListCommand extends Command
{
    protected $signature = 'preview:route:list
        {--json : Output named route metadata as JSON}
        {--filter= : Only list routes whose name contains this substring}';

    protected $description = 'List named Laravel routes that can be inspected before creating preview links.';

    private const READ_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function handle(): int
    {
        $routes = $this->routeRows();

        if ((bool) $this->option('json')) {
            $this->line(json_encode($routes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($routes === []) {
            $filter = $this->filter();

            $this->line($filter === null ? 'No named routes found.' : "No named routes matched filter [{$filter}].");

            return self::SUCCESS;
        }

        $this->line('Preview routes:');

        foreach ($routes as $route) {
            $this->line(sprintf(
                '%s | %s | %s | middleware: %d [%s] | %s: %s',
                $route['name'],
                implode(', ', $route['methods']),
                $route['uri'],
                $route['middleware_count'],
                implode(', ', $route['middleware']),
                $route['risk'] === 'write' ? 'risk' : 'safe',
                $this->textSafetyHint($route['risk']),
            ));
        }

        return self::SUCCESS;
    }

    /**
     * @return list<array{name: string, methods: list<string>, uri: string, middleware_count: int, middleware: list<string>, risk: string, safety_hint: string}>
     */
    private function routeRows(): array
    {
        $filter = $this->filter();
        $rows = [];

        foreach (Route::getRoutes()->getRoutesByName() as $name => $route) {
            if (! is_string($name) || $name === '') {
                continue;
            }

            if ($filter !== null && ! str_contains($name, $filter)) {
                continue;
            }

            $rows[] = $this->routeRow($name, $route);
        }

        usort($rows, fn (array $left, array $right): int => $left['name'] <=> $right['name']);

        return $rows;
    }

    /**
     * @return array{name: string, methods: list<string>, uri: string, middleware_count: int, middleware: list<string>, risk: string, safety_hint: string}
     */
    private function routeRow(string $name, LaravelRoute $route): array
    {
        $methods = array_values(array_map(strtoupper(...), $route->methods()));
        $middleware = $this->middleware($route);
        $risk = $this->hasWriteMethod($methods) ? 'write' : 'read';

        return [
            'name' => $name,
            'methods' => $methods,
            'uri' => $route->uri(),
            'middleware_count' => count($middleware),
            'middleware' => $middleware,
            'risk' => $risk,
            'safety_hint' => $risk === 'write'
                ? 'Write methods can run application side effects if previewed.'
                : 'Read-only route metadata; listing does not execute the route.',
        ];
    }

    /**
     * @return list<string>
     */
    private function middleware(LaravelRoute $route): array
    {
        return array_values(array_map(
            fn (mixed $middleware): string => is_object($middleware) ? $middleware::class : (string) $middleware,
            $route->gatherMiddleware(),
        ));
    }

    /**
     * @param list<string> $methods
     */
    private function hasWriteMethod(array $methods): bool
    {
        foreach ($methods as $method) {
            if (! in_array($method, self::READ_METHODS, true)) {
                return true;
            }
        }

        return false;
    }

    private function filter(): ?string
    {
        $filter = $this->option('filter');

        if (! is_string($filter) || trim($filter) === '') {
            return null;
        }

        return trim($filter);
    }

    private function textSafetyHint(string $risk): string
    {
        return $risk === 'write'
            ? 'write method; do not preview without side-effect isolation'
            : 'read-only route';
    }
}
