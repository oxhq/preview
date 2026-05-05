<?php

declare(strict_types=1);

namespace Oxhq\Preview\Commands;

use Illuminate\Console\Command;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;

final class RouteDoctorCommand extends Command
{
    protected $signature = 'preview:route:doctor {--json : Output route preview diagnostics as JSON}';

    protected $description = 'Diagnose route preview readiness without executing routes or opening preview links.';

    private const READ_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    /** @var list<string> */
    private const SUPPORTED_FAKES = ['queue', 'mail', 'http', 'events'];

    public function handle(): int
    {
        $diagnostics = $this->diagnostics();

        if ((bool) $this->option('json')) {
            $this->line(json_encode($diagnostics, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->line('Route preview diagnostics:');
        $this->line(' - Config enabled: '.$this->yesNo($diagnostics['config_enabled']));
        $this->line(' - Preview path: '.$diagnostics['preview_path']);
        $this->line(' - App key present: '.$this->yesNo($diagnostics['app_key_present']));
        $this->line(' - Preview access route: '.$this->yesNo($diagnostics['preview_access_route_exists']));
        $this->line(' - Named routes: '.$diagnostics['named_route_count']);
        $this->line(' - Read routes: '.$diagnostics['read_route_count']);
        $this->line(' - Write routes: '.$diagnostics['write_route_count']);
        $this->line(' - Supported fakes: '.implode(', ', $diagnostics['supported_fakes']));

        $this->writeList('Issues', $diagnostics['issues']);
        $this->writeList('Warnings', $diagnostics['warnings']);

        return self::SUCCESS;
    }

    /**
     * @return array{
     *     config_enabled: bool,
     *     preview_path: string,
     *     app_key_present: bool,
     *     preview_access_route_exists: bool,
     *     named_route_count: int,
     *     write_route_count: int,
     *     read_route_count: int,
     *     supported_fakes: list<string>,
     *     issues: list<string>,
     *     warnings: list<string>
     * }
     */
    private function diagnostics(): array
    {
        $configEnabled = (bool) config('preview.route_preview.enabled', true);
        $previewPath = config('preview.route_preview.path', '/__preview/route/{route}');
        $appKeyPresent = $this->appKeyPresent();
        $routeSummary = $this->routeSummary();

        $issues = [];

        if (! $configEnabled) {
            $issues[] = 'route preview disabled';
        }

        if (! $appKeyPresent) {
            $issues[] = 'missing APP_KEY/config app.key';
        }

        if (! $routeSummary['preview_access_route_exists']) {
            $issues[] = 'preview access route missing';
        }

        $warnings = [];

        if ($routeSummary['write'] > 0) {
            $warnings[] = 'write routes exist and require explicit opt-in/isolation';
        }

        return [
            'config_enabled' => $configEnabled,
            'preview_path' => is_string($previewPath) ? $previewPath : '',
            'app_key_present' => $appKeyPresent,
            'preview_access_route_exists' => $routeSummary['preview_access_route_exists'],
            'named_route_count' => $routeSummary['named'],
            'write_route_count' => $routeSummary['write'],
            'read_route_count' => $routeSummary['read'],
            'supported_fakes' => self::SUPPORTED_FAKES,
            'issues' => $issues,
            'warnings' => $warnings,
        ];
    }

    private function appKeyPresent(): bool
    {
        $key = config('app.key');

        return is_string($key) && trim($key) !== '';
    }

    /** @return array{named: int, write: int, read: int, preview_access_route_exists: bool} */
    private function routeSummary(): array
    {
        $summary = [
            'named' => 0,
            'write' => 0,
            'read' => 0,
            'preview_access_route_exists' => false,
        ];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! $route instanceof LaravelRoute) {
                continue;
            }

            $name = $route->getName();

            if (! is_string($name) || $name === '') {
                continue;
            }

            $summary['named']++;

            if ($name === 'preview.route.access') {
                $summary['preview_access_route_exists'] = true;
            }

            if ($this->hasWriteMethod(array_values(array_map(strtoupper(...), $route->methods())))) {
                $summary['write']++;

                continue;
            }

            $summary['read']++;
        }

        return $summary;
    }

    /** @param list<string> $methods */
    private function hasWriteMethod(array $methods): bool
    {
        foreach ($methods as $method) {
            if (! in_array($method, self::READ_METHODS, true)) {
                return true;
            }
        }

        return false;
    }

    private function yesNo(bool $value): string
    {
        return $value ? 'yes' : 'no';
    }

    /** @param list<string> $items */
    private function writeList(string $label, array $items): void
    {
        if ($items === []) {
            $this->line($label.': none');

            return;
        }

        $this->line($label.':');

        foreach ($items as $item) {
            $this->line(' - '.$item);
        }
    }
}
