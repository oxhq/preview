<?php

declare(strict_types=1);

namespace Oxhq\Preview\Commands;

use Illuminate\Console\Command;
use RuntimeException;

final class ScenarioMakeCommand extends Command
{
    protected $signature = 'preview:scenario:make
        {name : Scenario name}
        {--capture=* : Capture ID or event name to replay; may be repeated}
        {--route=* : Named route to preview; may be repeated}
        {--param=* : Route parameter as "route:key=value" or "route.key=value"; may be repeated}
        {--route-session=* : Route session value as "route:key=value" or "route.key=value"; may be repeated}
        {--route-guard=* : Route guard as "route=guard"; may be repeated}
        {--route-user=* : Route user context as "route:id:model"; may be repeated}
        {--route-readonly-db=* : Route name that should request readonly database preview; may be repeated}
        {--route-fake=* : Route fake as "route:fake"; may be repeated}
        {--route-status=* : Route expected HTTP status as "route=200"; may be repeated}
        {--route-output-contains=* : Route expected response text as "route=text"; may be repeated}
        {--fake=* : Laravel fake to apply, such as queue, mail, http, or events; may be repeated}
        {--seed= : Seeder class to run before replay}
        {--note= : Scenario note}
        {--force : Overwrite an existing scenario file}';

    protected $description = 'Create a local Laravel Preview scenario file.';

    public function handle(): int
    {
        $name = trim((string) $this->argument('name'));
        $path = $this->path($name);

        try {
            if (file_exists($path) && ! (bool) $this->option('force')) {
                throw new RuntimeException("Scenario file [{$path}] already exists. Pass --force to overwrite it.");
            }

            $this->ensureDirectory(dirname($path));
            file_put_contents($path, $this->scenarioPhp(
                name: $name,
                seed: $this->optionalString($this->option('seed')),
                routes: $this->stringList((array) $this->option('route')),
                routeParameters: $this->routeParameters((array) $this->option('param')),
                routeContext: $this->routeContext(
                    routeSessions: (array) $this->option('route-session'),
                    routeGuards: (array) $this->option('route-guard'),
                    routeUsers: (array) $this->option('route-user'),
                    readonlyRoutes: (array) $this->option('route-readonly-db'),
                    routeFakes: (array) $this->option('route-fake'),
                ),
                routeExpectations: $this->routeExpectations(
                    routeStatuses: (array) $this->option('route-status'),
                    routeOutputs: (array) $this->option('route-output-contains'),
                ),
                captures: $this->stringList((array) $this->option('capture')),
                fakes: $this->stringList((array) $this->option('fake')),
                notes: $this->optionalString($this->option('note')),
            ));
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Scenario [{$name}] created.");
        $this->line($path);

        return self::SUCCESS;
    }

    private function path(string $name): string
    {
        $root = rtrim((string) config('preview.scenario_path'), DIRECTORY_SEPARATOR);

        return $root.DIRECTORY_SEPARATOR.$this->safeSegment($name).'.php';
    }

    /**
     * @param list<string> $routes
     * @param array<string, array<string, string>> $routeParameters
     * @param array<string, array<string, mixed>> $routeContext
     * @param array<string, array<string, mixed>> $routeExpectations
     * @param list<string> $captures
     * @param list<string> $fakes
     */
    private function scenarioPhp(
        string $name,
        ?string $seed,
        array $routes,
        array $routeParameters,
        array $routeContext,
        array $routeExpectations,
        array $captures,
        array $fakes,
        ?string $notes,
    ): string {
        return "<?php\n\n"
            ."use Oxhq\\Preview\\Scenario\\Scenario;\n\n"
            .'return new Scenario('."\n"
            .'    name: '.$this->export($name).",\n"
            .'    seed: '.$this->export($seed).",\n"
            .'    routes: '.$this->export($routes).",\n"
            .'    routeParameters: '.$this->export($routeParameters).",\n"
            .'    routeContext: '.$this->export($routeContext).",\n"
            .'    routeExpectations: '.$this->export($routeExpectations).",\n"
            .'    captures: '.$this->export($captures).",\n"
            .'    fakes: '.$this->export($fakes).",\n"
            .'    notes: '.$this->export($notes).",\n"
            .");\n";
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function stringList(array $values): array
    {
        $normalized = [];

        foreach ($values as $value) {
            if (! is_scalar($value)) {
                continue;
            }

            $value = trim((string) $value);

            if ($value !== '') {
                $normalized[] = $value;
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param list<string> $values
     * @return array<string, array<string, string>>
     */
    private function routeParameters(array $values): array
    {
        $parameters = [];

        foreach ($values as $value) {
            if (! is_scalar($value)) {
                continue;
            }

            $value = trim((string) $value);

            if ($value === '') {
                continue;
            }

            [$route, $key, $parameterValue] = $this->parseRouteParameter($value);
            $parameters[$route][$key] = $parameterValue;
        }

        return $parameters;
    }

    /**
     * @param list<string> $routeSessions
     * @param list<string> $routeGuards
     * @param list<string> $routeUsers
     * @param list<string> $readonlyRoutes
     * @param list<string> $routeFakes
     * @return array<string, array<string, mixed>>
     */
    private function routeContext(
        array $routeSessions,
        array $routeGuards,
        array $routeUsers,
        array $readonlyRoutes,
        array $routeFakes,
    ): array {
        $context = [];

        foreach ($this->routeParameters($routeSessions) as $route => $session) {
            $context[$route]['session'] = $session;
        }

        foreach ($routeGuards as $value) {
            if (! is_scalar($value)) {
                continue;
            }

            [$route, $guard] = $this->parseRouteAssignment((string) $value, 'guard');
            $context[$route]['guard'] = $guard;
        }

        foreach ($routeUsers as $value) {
            if (! is_scalar($value)) {
                continue;
            }

            [$route, $userId, $userModel] = $this->parseRouteUser((string) $value);
            $context[$route]['user_id'] = $userId;
            $context[$route]['user_model'] = $userModel;
        }

        foreach ($this->stringList($readonlyRoutes) as $route) {
            $context[$route]['readonly_db'] = true;
        }

        foreach ($routeFakes as $value) {
            if (! is_scalar($value)) {
                continue;
            }

            [$route, $fake] = $this->parseRouteFake((string) $value);
            $context[$route]['fakes'][] = $fake;
            $context[$route]['fakes'] = array_values(array_unique($context[$route]['fakes']));
        }

        ksort($context);

        return $context;
    }

    /**
     * @param list<string> $routeStatuses
     * @param list<string> $routeOutputs
     * @return array<string, array<string, mixed>>
     */
    private function routeExpectations(array $routeStatuses, array $routeOutputs): array
    {
        $expectations = [];

        foreach ($routeStatuses as $value) {
            if (! is_scalar($value)) {
                continue;
            }

            [$route, $status] = $this->parseRouteStatus((string) $value);
            $expectations[$route]['status'] = $status;
        }

        foreach ($routeOutputs as $value) {
            if (! is_scalar($value)) {
                continue;
            }

            [$route, $output] = $this->parseRouteAssignment((string) $value, 'output');
            $expectations[$route]['output_contains'] = $output;
        }

        foreach ($expectations as $route => $expectation) {
            if (! array_key_exists('status', $expectation)) {
                throw new RuntimeException("Scenario route output expectation for [{$route}] requires --route-status={$route}=<status>.");
            }
        }

        ksort($expectations);

        return $expectations;
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function parseRouteParameter(string $value): array
    {
        [$left, $parameterValue] = explode('=', $value, 2) + [1 => ''];

        if (str_contains($left, ':')) {
            [$route, $key] = explode(':', $left, 2);
        } else {
            $lastDot = strrpos($left, '.');

            if ($lastDot === false) {
                throw new RuntimeException("Scenario route parameter [{$value}] must use route:key=value or route.key=value.");
            }

            $route = substr($left, 0, $lastDot);
            $key = substr($left, $lastDot + 1);
        }

        $route = trim($route);
        $key = trim($key);

        if ($route === '' || $key === '') {
            throw new RuntimeException("Scenario route parameter [{$value}] must include a route and key.");
        }

        return [$route, $key, trim($parameterValue)];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function parseRouteAssignment(string $value, string $label): array
    {
        [$route, $assigned] = explode('=', trim($value), 2) + [1 => ''];
        $route = trim($route);
        $assigned = trim($assigned);

        if ($route === '' || $assigned === '') {
            throw new RuntimeException("Scenario route {$label} [{$value}] must use route={$label}.");
        }

        return [$route, $assigned];
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function parseRouteStatus(string $value): array
    {
        [$route, $status] = $this->parseRouteAssignment($value, 'status');

        if (preg_match('/^\d{3}$/', $status) !== 1 || (int) $status < 100 || (int) $status > 599) {
            throw new RuntimeException("Scenario route status [{$value}] must use route=HTTP_STATUS with a status between 100 and 599.");
        }

        return [$route, (int) $status];
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function parseRouteUser(string $value): array
    {
        $parts = array_map('trim', explode(':', trim($value), 3));

        if (count($parts) !== 3 || in_array('', $parts, true)) {
            throw new RuntimeException("Scenario route user [{$value}] must use route:id:model.");
        }

        return [$parts[0], $parts[1], $parts[2]];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function parseRouteFake(string $value): array
    {
        $parts = array_map('trim', explode(':', trim($value), 2));

        if (count($parts) !== 2 || in_array('', $parts, true)) {
            throw new RuntimeException("Scenario route fake [{$value}] must use route:fake.");
        }

        return [$parts[0], $parts[1]];
    }

    private function optionalString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function ensureDirectory(string $path): void
    {
        if (! is_dir($path) && ! mkdir($path, 0775, true) && ! is_dir($path)) {
            throw new RuntimeException("Directory [{$path}] could not be created.");
        }
    }

    private function safeSegment(string $value): string
    {
        return preg_replace('/[^A-Za-z0-9_.-]/', '-', $value) ?: 'preview-scenario';
    }

    private function export(mixed $value): string
    {
        return var_export($value, true);
    }
}
