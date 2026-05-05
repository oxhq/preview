<?php

declare(strict_types=1);

namespace Oxhq\Preview\Route;

use DateTimeImmutable;
use Illuminate\Routing\Router;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Carbon;
use RuntimeException;

final class RoutePreviewService
{
    public function __construct(
        private readonly Router $router,
        private readonly UrlGenerator $url,
    ) {
    }

    /**
     * @param array<string, string> $parameters
     * @param array<string, string|mixed> $session
     */
    public function preview(
        string $routeName,
        array $parameters = [],
        string $ttl = '2h',
        bool $readonlyDb = false,
        ?string $guard = null,
        array $session = [],
        bool $allowWrite = false,
        array $fakes = [],
    ): RoutePreview {
        $route = $this->router->getRoutes()->getByName($routeName);

        if ($route === null) {
            throw new RuntimeException("Route [{$routeName}] was not found.");
        }

        $this->ensureRequiredParameters($routeName, $route->uri(), $route->getDomain(), $parameters);

        $methods = array_values(array_unique($route->methods()));
        $writeMethods = array_values(array_diff($methods, ['GET', 'HEAD']));

        if ($writeMethods !== [] && ! $allowWrite) {
            throw new RuntimeException(sprintf(
                'Route [%s] includes non-read methods [%s]. Pass allowWrite=true to preview it.',
                $routeName,
                implode(', ', $writeMethods),
            ));
        }

        $expiresAt = $this->expiresAt($ttl);
        $middleware = array_values(array_map('strval', $route->gatherMiddleware()));
        $executionMethod = $this->executionMethod($methods, $allowWrite);
        $session = $this->normalizeStringMap($session);
        $fakes = $this->normalizeFakes($fakes);
        $query = [
            'route' => $routeName,
            '_preview_params' => $this->encodeParameters($parameters),
            '_preview_method' => $executionMethod,
        ];

        if ($session !== []) {
            $query['_preview_session'] = $this->encodeParameters($session);
        }

        if ($allowWrite) {
            $query['_preview_allow_write'] = '1';
        }

        if ($readonlyDb) {
            $query['_preview_readonly_db'] = '1';
        }

        if ($guard !== null && trim($guard) !== '') {
            $query['_preview_guard'] = trim($guard);
        }

        if ($fakes !== []) {
            $query['_preview_fakes'] = implode(',', $fakes);
        }

        $warnings = [];

        if ($readonlyDb) {
            $warnings[] = '--readonly-db only covers database writes handled inside the preview request; queues, mail, cache, filesystem, events, and external HTTP are not rolled back.';
        }

        if ($writeMethods !== []) {
            $warnings[] = sprintf(
                'Route includes non-read methods [%s]. Database rollback does not protect queues, mail, cache, filesystem writes, events, or external HTTP calls.',
                implode(', ', $writeMethods),
            );
        }

        if ($guard !== null && trim($guard) !== '') {
            $warnings[] = sprintf('Guard [%s] is recorded on the preview link; it does not bypass application authorization.', trim($guard));
        }

        if ($session !== []) {
            $warnings[] = 'Session context is attached to the proxied request; it does not authenticate a user or bypass authorization.';
        }

        foreach ($fakes as $fake) {
            $warnings[] = "Preview will request fake [{$fake}] during proxied execution.";
        }

        return new RoutePreview(
            name: $routeName,
            uri: $route->uri(),
            action: $route->getActionName(),
            domain: $route->getDomain(),
            methods: $methods,
            middleware: $middleware,
            executionMethod: $executionMethod,
            url: $this->url->temporarySignedRoute('preview.route.access', $expiresAt, $query),
            expiresAt: $expiresAt,
            parameters: $parameters,
            session: $session,
            readonlyDb: $readonlyDb,
            guard: $guard !== null && trim($guard) !== '' ? trim($guard) : null,
            fakes: $fakes,
            warnings: $warnings,
        );
    }

    /**
     * @param list<string> $methods
     */
    private function executionMethod(array $methods, bool $allowWrite): string
    {
        if (in_array('GET', $methods, true)) {
            return 'GET';
        }

        if (in_array('HEAD', $methods, true)) {
            return 'HEAD';
        }

        return $allowWrite ? $methods[0] : 'GET';
    }

    /**
     * @param array<string, string> $parameters
     */
    private function ensureRequiredParameters(string $routeName, string $uri, ?string $domain, array $parameters): void
    {
        $required = array_unique(array_merge(
            $this->requiredParameterNames($uri),
            $domain === null ? [] : $this->requiredParameterNames($domain),
        ));
        $missing = array_values(array_diff($required, array_keys($parameters)));

        if ($missing !== []) {
            throw new RuntimeException(sprintf(
                'Route [%s] requires missing parameters [%s]. Pass them with --param=key=value.',
                $routeName,
                implode(', ', $missing),
            ));
        }
    }

    /**
     * @return list<string>
     */
    private function requiredParameterNames(string $value): array
    {
        preg_match_all('/\{([^?}]+)\}/', $value, $matches);

        return array_values(array_filter(array_map('strval', $matches[1] ?? [])));
    }

    /**
     * @param array<int, string|mixed> $fakes
     * @return list<string>
     */
    private function normalizeFakes(array $fakes): array
    {
        $allowed = ['queue', 'mail', 'http', 'events'];
        $normalized = [];

        foreach ($fakes as $fake) {
            if (! is_string($fake)) {
                continue;
            }

            $fake = strtolower(trim($fake));

            if (in_array($fake, $allowed, true)) {
                $normalized[] = $fake;
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param array<string, string|mixed> $values
     * @return array<string, string>
     */
    private function normalizeStringMap(array $values): array
    {
        $normalized = [];

        foreach ($values as $key => $value) {
            if (! is_string($key) || $key === '' || ! is_scalar($value)) {
                continue;
            }

            $normalized[$key] = (string) $value;
        }

        return $normalized;
    }

    private function expiresAt(string $ttl): DateTimeImmutable
    {
        if (! preg_match('/^([1-9][0-9]*)([smhd])$/', trim($ttl), $matches)) {
            throw new RuntimeException('The --ttl option must use a duration like 30m, 2h, or 1d.');
        }

        $amount = (int) $matches[1];
        $seconds = match ($matches[2]) {
            's' => $amount,
            'm' => $amount * 60,
            'h' => $amount * 3600,
            'd' => $amount * 86400,
        };

        $timestamp = Carbon::now('UTC')->getTimestamp() + $seconds;

        return (new DateTimeImmutable('@'.$timestamp));
    }

    /**
     * @param array<string, string> $parameters
     */
    private function encodeParameters(array $parameters): string
    {
        $json = json_encode($parameters, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }
}
