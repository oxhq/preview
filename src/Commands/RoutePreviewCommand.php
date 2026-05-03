<?php

declare(strict_types=1);

namespace Oxhq\Preview\Commands;

use Illuminate\Console\Command;
use Oxhq\Preview\Route\RoutePreviewService;
use RuntimeException;

final class RoutePreviewCommand extends Command
{
    protected $signature = 'preview:route
        {route : Named Laravel route to preview}
        {--ttl=2h : Signed preview link lifetime, such as 30m, 2h, or 1d}
        {--param=* : Route parameter as "name=value"; may be repeated}
        {--readonly-db : Mark the link with database-readonly intent and print safety warnings}
        {--guard= : Guard/session context label to record on the preview link}
        {--allow-write : Explicitly allow non-GET/HEAD routes}';

    protected $description = 'Create a signed, time-limited preview link for a named Laravel route.';

    public function __construct(
        private readonly RoutePreviewService $routes,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $preview = $this->routes->preview(
                routeName: (string) $this->argument('route'),
                parameters: $this->parameters((array) $this->option('param')),
                ttl: (string) $this->option('ttl'),
                readonlyDb: (bool) $this->option('readonly-db'),
                guard: is_string($this->option('guard')) ? (string) $this->option('guard') : null,
                allowWrite: (bool) $this->option('allow-write'),
            );
        } catch (RuntimeException $exception) {
            $this->error(str_replace('Pass allowWrite=true', 'Pass --allow-write', $exception->getMessage()));

            return self::FAILURE;
        }

        $this->line("Route: {$preview->name}");
        $this->line("URI: {$preview->uri}");
        $this->line('Methods: '.implode(', ', $preview->methods));
        $this->line('Middleware: '.($preview->middleware === [] ? 'none' : implode(', ', $preview->middleware)));
        $this->line("Action: {$preview->action}");

        if ($preview->domain !== null) {
            $this->line("Domain: {$preview->domain}");
        }

        if ($preview->parameters !== []) {
            $this->line('Parameters: '.$this->formatParameters($preview->parameters));
        }

        if ($preview->guard !== null) {
            $this->line("Guard: {$preview->guard}");
        }

        $this->line('Readonly DB: '.($preview->readonlyDb ? 'requested' : 'not requested'));
        $this->line("Expiration: {$preview->expiresAt->format('Y-m-d H:i:s')} UTC");
        $this->line('Preview path: '.(parse_url($preview->url, PHP_URL_PATH) ?: '/__preview/route/'.$preview->name));

        $query = $this->query($preview->url);

        if (isset($query['signature'])) {
            $this->line('Preview signature: signature='.$query['signature']);
        }

        if (isset($query['_preview_params'])) {
            $this->line('Preview parameters token: _preview_params='.$query['_preview_params']);
        }

        $this->line("Preview URL: {$preview->url}");

        if ($preview->warnings !== []) {
            $this->line('Warnings:');
        }

        foreach ($preview->warnings as $warning) {
            $this->line(" - {$warning}");
        }

        return self::SUCCESS;
    }

    /**
     * @param list<string> $values
     * @return array<string, string>
     */
    private function parameters(array $values): array
    {
        $parameters = [];

        foreach ($values as $value) {
            [$name, $parameterValue] = explode('=', $value, 2) + [1 => ''];
            $name = trim($name);

            if ($name !== '') {
                $parameters[$name] = trim($parameterValue);
            }
        }

        return $parameters;
    }

    /**
     * @param array<string, string> $parameters
     */
    private function formatParameters(array $parameters): string
    {
        $formatted = [];

        foreach ($parameters as $name => $value) {
            $formatted[] = "{$name}={$value}";
        }

        return implode(', ', $formatted);
    }

    /**
     * @return array<string, string>
     */
    private function query(string $url): array
    {
        $queryString = parse_url($url, PHP_URL_QUERY);

        if (! is_string($queryString)) {
            return [];
        }

        parse_str($queryString, $query);

        return array_filter($query, is_string(...));
    }
}
