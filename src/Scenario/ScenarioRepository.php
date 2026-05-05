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

        return $scenario;
    }
}
