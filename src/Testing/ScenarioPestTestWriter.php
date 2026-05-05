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

        if ($scenario->routes === []) {
            $php .= "    // Routes: none listed for this scenario.\n";
        }

        foreach ($scenario->routes as $route) {
            $php .= '    // '.$this->commentLine("Route replay expected: {$route}")."\n";
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

    private function safeSegment(string $value): string
    {
        return preg_replace('/[^A-Za-z0-9_.-]/', '-', $value) ?: 'preview-scenario';
    }
}
