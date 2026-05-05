<?php

declare(strict_types=1);

namespace Oxhq\Preview\Testing;

use Oxhq\Preview\Scenario\Scenario;
use RuntimeException;

final class ScenarioPestTestWriter
{
    public function __construct(
        private readonly ?string $testPath = null,
    ) {
    }

    public function write(Scenario $scenario): string
    {
        $path = $this->testFilePath($scenario);
        $this->ensureDirectory(dirname($path));
        file_put_contents($path, $this->testPhp($scenario));

        return $path;
    }

    private function testPhp(Scenario $scenario): string
    {
        $php = "<?php\n\n"
            ."use Oxhq\\Preview\\Scenario\\ScenarioRunner;\n\n"
            .'it('.$this->exportString("replays {$scenario->name} preview scenario").", function () {\n";

        if ($scenario->seed !== null) {
            $php .= '    // '.$this->commentLine("Precondition: run seed [{$scenario->seed}] before replaying this scenario.")."\n";
        } else {
            $php .= "    // Precondition: no scenario seed configured.\n";
        }

        if ($scenario->captures === []) {
            $php .= "    // Captures: none listed for this scenario.\n";
        }

        foreach ($scenario->captures as $capture) {
            $php .= '    // '.$this->commentLine("Capture fixture must exist locally: {$capture}")."\n";
        }

        if ($scenario->fakes !== []) {
            $php .= '    // '.$this->commentLine('Scenario fake boundaries requested: '.$this->formatList($scenario->fakes).'.')."\n";
        }

        if ($scenario->routes === []) {
            $php .= "    // Routes: none listed for this scenario.\n";
        }

        foreach ($scenario->routes as $route) {
            $php .= '    // '.$this->commentLine("Route replay expected: {$route}")."\n";
            $php .= $this->routePreconditionComments($scenario, $route);
        }

        $php .= "\n"
            ."    \$result = app(ScenarioRunner::class)->replay(".$this->exportString($scenario->name).", 'exact');\n\n"
            ."    \$this->assertSame(".$this->exportString($scenario->name).", \$result->scenario->name);\n"
            ."    \$this->assertSame('exact', \$result->mode);\n";

        if ($scenario->seed !== null) {
            $php .= "    \$this->assertSame(".$this->exportString($scenario->seed).", \$result->seed);\n";
        } else {
            $php .= "    \$this->assertNull(\$result->seed);\n";
        }

        $php .= "    \$this->assertCount(".count($scenario->captures).", \$result->captures);\n";

        foreach ($scenario->captures as $index => $capture) {
            $php .= "    \$this->assertSame(".$this->exportString($capture).", \$result->captures[{$index}]['id'] ?? null);\n";
        }

        if ($scenario->captures !== []) {
            $php .= "    \$this->assertCount(".count($scenario->captures).", \$result->dispatches);\n";
        }

        $php .= "    \$this->assertCount(".count($scenario->routes).", \$result->routes);\n";

        foreach ($scenario->routes as $index => $route) {
            $php .= "    \$this->assertSame(".$this->exportString($route).", \$result->routes[{$index}]->preview->name);\n"
                ."    \$this->assertTrue(\$result->routes[{$index}]->successful());\n"
                ."    \$this->assertGreaterThanOrEqual(200, \$result->routes[{$index}]->response->getStatusCode());\n"
                ."    \$this->assertLessThan(300, \$result->routes[{$index}]->response->getStatusCode());\n";
        }

        $php .= "});\n";

        return $php;
    }

    private function testFilePath(Scenario $scenario): string
    {
        return $this->testRoot()
            .DIRECTORY_SEPARATOR.'Preview'
            .DIRECTORY_SEPARATOR.'Scenario'
            .DIRECTORY_SEPARATOR.$this->safeSegment($scenario->name).'Test.php';
    }

    private function testRoot(): string
    {
        if ($this->testPath !== null) {
            return rtrim($this->testPath, DIRECTORY_SEPARATOR);
        }

        $configured = function_exists('config') ? config('preview.test_path') : null;

        if (is_string($configured) && $configured !== '') {
            return rtrim($configured, DIRECTORY_SEPARATOR);
        }

        return getcwd().DIRECTORY_SEPARATOR.'tests'.DIRECTORY_SEPARATOR.'Feature';
    }

    private function ensureDirectory(string $path): void
    {
        if (! is_dir($path) && ! mkdir($path, 0775, true) && ! is_dir($path)) {
            throw new RuntimeException("Directory [{$path}] could not be created.");
        }
    }

    private function exportString(string $value): string
    {
        return var_export($value, true);
    }

    private function commentLine(string $value): string
    {
        return str_replace('?>', '? >', str_replace(["\r", "\n"], ' ', $value));
    }

    private function routePreconditionComments(Scenario $scenario, string $route): string
    {
        $php = '';
        $parameters = $this->stringMap($scenario->routeParameters[$route] ?? []);
        $context = $this->routeContext($scenario, $route);
        $session = $this->stringMap($context['session'] ?? []);
        $guard = $this->optionalString($context['guard'] ?? null);
        $userId = $this->optionalString($context['user_id'] ?? null);
        $userModel = $this->optionalString($context['user_model'] ?? null);
        $routeFakes = $this->stringList($context['fakes'] ?? []);

        if ($parameters !== []) {
            $php .= '    // '.$this->commentLine("Route [{$route}] parameters required by scenario: ".$this->formatMap($parameters).'.')."\n";
        }

        if ($session !== []) {
            $php .= '    // '.$this->commentLine("Route [{$route}] session keys required by scenario: ".$this->formatList(array_keys($session)).'.')."\n";
        }

        if ($guard !== null) {
            $php .= '    // '.$this->commentLine("Route [{$route}] guard context requested: {$guard}.")."\n";
        }

        if ($userId !== null && $userModel !== null) {
            $php .= '    // '.$this->commentLine("Route [{$route}] user context requested: user id {$userId} via {$userModel}.")."\n";
        } elseif ($userId !== null) {
            $php .= '    // '.$this->commentLine("Route [{$route}] user context requested: user id {$userId}.")."\n";
        } elseif ($userModel !== null) {
            $php .= '    // '.$this->commentLine("Route [{$route}] user model context requested: {$userModel}.")."\n";
        }

        if (($context['readonly_db'] ?? false) === true) {
            $php .= '    // '.$this->commentLine("Route [{$route}] readonly-db requested.")."\n";
        }

        if ($routeFakes !== []) {
            $php .= '    // '.$this->commentLine("Route [{$route}] fake boundaries requested: ".$this->formatList($routeFakes).'.')."\n";
        }

        return $php;
    }

    /**
     * @return array<string, mixed>
     */
    private function routeContext(Scenario $scenario, string $route): array
    {
        $context = $scenario->routeContext[$route] ?? [];

        return is_array($context) ? $context : [];
    }

    /**
     * @return array<string, string>
     */
    private function stringMap(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

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

    /**
     * @return list<string>
     */
    private function stringList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

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

        return array_values($normalized);
    }

    private function optionalString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @param array<string, string> $values
     */
    private function formatMap(array $values): string
    {
        $formatted = [];

        foreach ($values as $key => $value) {
            $formatted[] = "{$key}={$value}";
        }

        return $this->formatList($formatted);
    }

    /**
     * @param list<string>|array<int|string, string> $values
     */
    private function formatList(array $values): string
    {
        return implode(', ', array_values($values));
    }

    private function safeSegment(string $value): string
    {
        return preg_replace('/[^A-Za-z0-9_.-]/', '-', $value) ?: 'preview-scenario';
    }
}
