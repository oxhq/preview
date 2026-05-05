<?php

declare(strict_types=1);

namespace Oxhq\Preview\Commands;

use Illuminate\Console\Command;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Throwable;

final class RouteExportCommand extends Command
{
    protected $signature = 'preview:route:export
        {route? : Named Laravel route to export}
        {--path= : Directory to write export into}
        {--json : Output export details as JSON}';

    protected $description = 'Export safe named Laravel route metadata as JSON.';

    private const READ_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function handle(): int
    {
        try {
            $root = $this->exportRoot();
            $routes = $this->routes();
            $files = [];

            foreach ($routes as $name => $route) {
                $exportPath = $this->exportPath($root, $name);
                $this->ensureDirectory($exportPath, $root);

                $file = $exportPath.DIRECTORY_SEPARATOR.'route.json';
                $this->writeJson(
                    $file,
                    $this->routeData($name, $route),
                    "Route [{$name}] export metadata could not be encoded.",
                );

                $files[] = $this->relativeFile($root, $file);
            }
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $routeNames = array_keys($routes);

        if ((bool) $this->option('json')) {
            $this->line($this->json([
                'routes' => $routeNames,
                'export_root' => $root,
                'files' => $files,
            ]));

            return self::SUCCESS;
        }

        if (count($routeNames) === 1) {
            $this->info("Exported route [{$routeNames[0]}].");
        } else {
            $this->info('Exported '.count($routeNames).' named routes.');
        }

        foreach ($files as $file) {
            $this->line($root.DIRECTORY_SEPARATOR.$file);
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, LaravelRoute>
     */
    private function routes(): array
    {
        $requested = $this->argument('route');
        $collection = Route::getRoutes();
        $collection->refreshNameLookups();
        $routes = $collection->getRoutesByName();

        if (is_string($requested) && trim($requested) !== '') {
            $name = trim($requested);
            $route = $routes[$name] ?? null;

            if (! $route instanceof LaravelRoute) {
                throw new RuntimeException("Route [{$name}] was not found.");
            }

            return [$name => $route];
        }

        $namedRoutes = [];

        foreach ($routes as $name => $route) {
            if (! is_string($name) || $name === '' || ! $route instanceof LaravelRoute) {
                continue;
            }

            $namedRoutes[$name] = $route;
        }

        ksort($namedRoutes);

        return $namedRoutes;
    }

    private function exportRoot(): string
    {
        $path = $this->option('path');

        if (is_string($path) && $path !== '') {
            return $this->resolveDirectory($path);
        }

        $configured = config('preview.export_path');

        if (is_string($configured) && $configured !== '') {
            return $this->resolveDirectory($configured);
        }

        if (function_exists('storage_path')) {
            return $this->resolveDirectory(storage_path('preview/exports/routes'));
        }

        return $this->resolveDirectory(getcwd().DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'preview'.DIRECTORY_SEPARATOR.'exports'.DIRECTORY_SEPARATOR.'routes');
    }

    private function exportPath(string $root, string $routeName): string
    {
        $path = $root.DIRECTORY_SEPARATOR.$this->safeSegment($routeName);
        $parent = realpath(dirname($path));

        if ($parent === false) {
            throw new RuntimeException("Export root [{$root}] could not be resolved.");
        }

        $candidate = $parent.DIRECTORY_SEPARATOR.basename($path);
        $this->assertInsideRoot($candidate, $root, "Route [{$routeName}] export path");

        return $candidate;
    }

    /**
     * @return array{name: string, methods: list<string>, uri: string, domain: ?string, action: string, middleware: list<string>, risk: string, safety_hint: string}
     */
    private function routeData(string $name, LaravelRoute $route): array
    {
        $methods = array_values(array_map(strtoupper(...), $route->methods()));
        $risk = $this->hasWriteMethod($methods) ? 'write' : 'read';

        return [
            'name' => $name,
            'methods' => $methods,
            'uri' => $route->uri(),
            'domain' => $route->getDomain(),
            'action' => $route->getActionName(),
            'middleware' => $this->middleware($route),
            'risk' => $risk,
            'safety_hint' => $risk === 'write'
                ? 'Write methods can run application side effects if previewed.'
                : 'Read-only route metadata; exporting does not execute the route.',
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

    private function resolveDirectory(string $path): string
    {
        if (! is_dir($path) && ! mkdir($path, 0775, true) && ! is_dir($path)) {
            throw new RuntimeException("Directory [{$path}] could not be created.");
        }

        $resolved = realpath($path);

        if ($resolved === false || ! is_dir($resolved)) {
            throw new RuntimeException("Directory [{$path}] could not be resolved.");
        }

        return rtrim($resolved, DIRECTORY_SEPARATOR);
    }

    private function ensureDirectory(string $path, string $root): void
    {
        if (! is_dir($path) && ! mkdir($path, 0775, true) && ! is_dir($path)) {
            throw new RuntimeException("Directory [{$path}] could not be created.");
        }

        $resolved = realpath($path);

        if ($resolved === false || ! is_dir($resolved)) {
            throw new RuntimeException("Directory [{$path}] could not be resolved.");
        }

        $this->assertInsideRoot($resolved, $root, "Directory [{$path}]");
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeJson(string $path, array $data, string $errorMessage): void
    {
        try {
            $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RuntimeException($errorMessage);
        }

        if (file_put_contents($path, $encoded.PHP_EOL) === false) {
            throw new RuntimeException("File [{$path}] could not be written.");
        }
    }

    /**
     * @param array<string, mixed> $value
     */
    private function json(array $value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function safeSegment(string $value): string
    {
        $segment = preg_replace('/[^A-Za-z0-9_.-]/', '-', $value) ?: 'route';

        return in_array($segment, ['.', '..'], true) ? 'route' : $segment;
    }

    private function relativeFile(string $root, string $file): string
    {
        $root = rtrim($this->normalizePath($root), DIRECTORY_SEPARATOR);
        $file = $this->normalizePath($file);

        if (DIRECTORY_SEPARATOR === '\\') {
            $rootCompare = strtolower($root);
            $fileCompare = strtolower($file);
        } else {
            $rootCompare = $root;
            $fileCompare = $file;
        }

        if ($fileCompare === $rootCompare || ! str_starts_with($fileCompare, $rootCompare.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException("File [{$file}] is outside the selected export root.");
        }

        return substr($file, strlen($root) + 1);
    }

    private function assertInsideRoot(string $path, string $root, string $label): void
    {
        $path = $this->normalizePath($path);
        $root = rtrim($this->normalizePath($root), DIRECTORY_SEPARATOR);

        if (DIRECTORY_SEPARATOR === '\\') {
            $path = strtolower($path);
            $root = strtolower($root);
        }

        if ($path !== $root && ! str_starts_with($path, $root.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException("{$label} is outside the selected export root.");
        }
    }

    private function normalizePath(string $path): string
    {
        return str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }
}
