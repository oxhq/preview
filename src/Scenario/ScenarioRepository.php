<?php

declare(strict_types=1);

namespace Oxhq\Preview\Scenario;

use RuntimeException;

final class ScenarioRepository
{
    public function __construct(
        private readonly string $path,
    ) {
    }

    /**
     * @return list<Scenario>
     */
    public function all(): array
    {
        if (! is_dir($this->path)) {
            return [];
        }

        $scenarios = [];

        foreach ($this->files() as $file) {
            $scenarios[] = $this->load($file);
        }

        usort($scenarios, fn (Scenario $a, Scenario $b): int => $a->name <=> $b->name);

        return $scenarios;
    }

    public function find(string $name): ?Scenario
    {
        foreach ($this->all() as $scenario) {
            if ($scenario->name === $name) {
                return $scenario;
            }
        }

        return null;
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * @return list<string>
     */
    private function files(): array
    {
        $files = glob(rtrim($this->path, DIRECTORY_SEPARATOR).'/*.php');

        if ($files === false) {
            return [];
        }

        sort($files);

        return array_values($files);
    }

    private function load(string $file): Scenario
    {
        $scenario = require $file;

        if (! $scenario instanceof Scenario) {
            throw new RuntimeException(sprintf(
                'Scenario file [%s] must return an instance of %s.',
                $file,
                Scenario::class,
            ));
        }

        if (trim($scenario->name) === '') {
            throw new RuntimeException(sprintf('Scenario file [%s] must define a non-empty scenario name.', $file));
        }

        return new Scenario(
            name: trim($scenario->name),
            seed: $scenario->seed === null || trim($scenario->seed) === '' ? null : trim($scenario->seed),
            routes: $this->normalizeList($scenario->routes),
            routeParameters: $scenario->routeParameters,
            captures: $this->normalizeList($scenario->captures),
            fakes: $this->normalizeFakes($scenario->fakes, $file),
            notes: $scenario->notes === null || trim($scenario->notes) === '' ? null : trim($scenario->notes),
            routeExpectations: $this->normalizeRouteExpectations($scenario->routeExpectations, $file),
            routeContext: $this->normalizeRouteContext($scenario->routeContext, $file),
        );
    }

    /**
     * @param list<string|mixed> $values
     * @return list<string>
     */
    private function normalizeList(array $values): array
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
     * @param list<string|mixed> $fakes
     * @return list<string>
     */
    private function normalizeFakes(array $fakes, string $file, ?string $routeName = null): array
    {
        $allowed = ['queue', 'mail', 'http', 'events'];
        $normalized = [];

        foreach ($fakes as $fake) {
            if (! is_scalar($fake)) {
                continue;
            }

            $fake = strtolower(trim((string) $fake));

            if ($fake === '') {
                continue;
            }

            if (! in_array($fake, $allowed, true)) {
                $location = $routeName === null ? '' : " route [{$routeName}]";

                throw new RuntimeException(sprintf(
                    'Scenario file [%s]%s defines unsupported fake [%s]. Supported fakes: queue, mail, http, events.',
                    $file,
                    $location,
                    $fake,
                ));
            }

            $normalized[] = $fake;
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param array<string, mixed> $routeContext
     * @return array<string, array{session?: array<string, string>, guard?: string, user_id?: string, user_model?: class-string|string, readonly_db?: bool, fakes?: list<string>}>
     */
    private function normalizeRouteContext(array $routeContext, string $file): array
    {
        $normalized = [];

        foreach ($routeContext as $routeName => $context) {
            if (! is_scalar($routeName)) {
                continue;
            }

            $routeName = trim((string) $routeName);

            if ($routeName === '') {
                continue;
            }

            if (! is_array($context)) {
                throw new RuntimeException(sprintf(
                    'Scenario file [%s] route [%s] context must be an array.',
                    $file,
                    $routeName,
                ));
            }

            $normalized[$routeName] = $this->normalizeSingleRouteContext($context, $file, $routeName);
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $context
     * @return array{session?: array<string, string>, guard?: string, user_id?: string, user_model?: class-string|string, readonly_db?: bool, fakes?: list<string>}
     */
    private function normalizeSingleRouteContext(array $context, string $file, string $routeName): array
    {
        $normalized = [];

        foreach ($context as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

            $key = match ($key) {
                'readonlyDb' => 'readonly_db',
                'userId' => 'user_id',
                'userModel' => 'user_model',
                default => $key,
            };

            if ($key === 'session') {
                $normalized['session'] = is_array($value)
                    ? $this->normalizeStringMap($value)
                    : $this->invalidRouteContext($file, $routeName, $key, 'an array');

                continue;
            }

            if (in_array($key, ['guard', 'user_id', 'user_model'], true)) {
                $value = $this->normalizeOptionalString($value);

                if ($value !== null) {
                    $normalized[$key] = $value;
                }

                continue;
            }

            if ($key === 'readonly_db') {
                $normalized['readonly_db'] = is_bool($value)
                    ? $value
                    : $this->invalidRouteContext($file, $routeName, $key, 'a boolean');

                continue;
            }

            if ($key === 'fakes') {
                $normalized['fakes'] = is_array($value)
                    ? $this->normalizeFakes($value, $file, $routeName)
                    : $this->invalidRouteContext($file, $routeName, $key, 'an array');

                continue;
            }

            $this->invalidRouteContext($file, $routeName, $key, 'a supported key');
        }

        return array_filter(
            $normalized,
            fn (mixed $value): bool => ! ($value === [] || $value === null),
        );
    }

    /**
     * @param array<string|int, mixed> $values
     * @return array<string, string>
     */
    private function normalizeStringMap(array $values): array
    {
        $normalized = [];

        foreach ($values as $key => $value) {
            if (! is_scalar($key) || ! is_scalar($value)) {
                continue;
            }

            $key = trim((string) $key);

            if ($key !== '') {
                $normalized[$key] = (string) $value;
            }
        }

        return $normalized;
    }

    private function normalizeOptionalString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @param array<string, mixed> $routeExpectations
     * @return array<string, array{status: int, output_contains?: string}>
     */
    private function normalizeRouteExpectations(array $routeExpectations, string $file): array
    {
        $normalized = [];

        foreach ($routeExpectations as $routeName => $expectation) {
            if (! is_scalar($routeName)) {
                continue;
            }

            $routeName = trim((string) $routeName);

            if ($routeName === '') {
                continue;
            }

            if (! is_array($expectation)) {
                throw new RuntimeException(sprintf(
                    'Scenario file [%s] route [%s] expectation must be an array.',
                    $file,
                    $routeName,
                ));
            }

            $normalized[$routeName] = $this->normalizeSingleRouteExpectation($expectation, $file, $routeName);
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $expectation
     * @return array{status: int, output_contains?: string}
     */
    private function normalizeSingleRouteExpectation(array $expectation, string $file, string $routeName): array
    {
        $normalized = [];

        foreach ($expectation as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

            $key = match ($key) {
                'outputContains' => 'output_contains',
                default => $key,
            };

            if ($key === 'status') {
                $status = $this->normalizeHttpStatus($value);

                if ($status === null) {
                    $this->invalidRouteExpectation($file, $routeName, $key, 'an HTTP status code');
                }

                $normalized['status'] = $status;

                continue;
            }

            if ($key === 'output_contains') {
                $outputContains = $this->normalizeOptionalString($value);

                if ($outputContains !== null) {
                    $normalized['output_contains'] = $outputContains;
                }

                continue;
            }

            $this->invalidRouteExpectation($file, $routeName, $key, 'a supported key');
        }

        if (! array_key_exists('status', $normalized)) {
            $this->invalidRouteExpectation($file, $routeName, 'status', 'an HTTP status code');
        }

        return $normalized;
    }

    private function normalizeHttpStatus(mixed $value): ?int
    {
        if (! is_int($value) && ! (is_string($value) && preg_match('/^\s*\d{3}\s*$/', $value) === 1)) {
            return null;
        }

        $status = (int) $value;

        return $status >= 100 && $status <= 599 ? $status : null;
    }

    private function invalidRouteExpectation(string $file, string $routeName, string $key, string $expected): never
    {
        throw new RuntimeException(sprintf(
            'Scenario file [%s] route [%s] expectation key [%s] must be %s.',
            $file,
            $routeName,
            $key,
            $expected,
        ));
    }

    private function invalidRouteContext(string $file, string $routeName, string $key, string $expected): never
    {
        throw new RuntimeException(sprintf(
            'Scenario file [%s] route [%s] context key [%s] must be %s.',
            $file,
            $routeName,
            $key,
            $expected,
        ));
    }
}
