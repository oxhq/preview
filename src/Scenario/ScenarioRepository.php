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
    private function normalizeFakes(array $fakes, string $file): array
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
                throw new RuntimeException(sprintf(
                    'Scenario file [%s] defines unsupported fake [%s]. Supported fakes: queue, mail, http, events.',
                    $file,
                    $fake,
                ));
            }

            $normalized[] = $fake;
        }

        return array_values(array_unique($normalized));
    }
}
