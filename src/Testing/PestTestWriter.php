<?php

declare(strict_types=1);

namespace Oxhq\Preview\Testing;

use Oxhq\Preview\Capture\CaptureRecord;
use RuntimeException;

final class PestTestWriter
{
    public function __construct(
        private readonly ?string $testPath = null,
        private readonly ?FixtureWriter $fixtures = null,
    ) {
    }

    public function write(CaptureRecord $record, bool $providerCanSign = false): string
    {
        $fixtures = $this->fixtures ?? new FixtureWriter();
        $fixturePath = $fixtures->fixturePath($record);

        if (! is_file($fixturePath)) {
            $fixtures->write($record, $providerCanSign);
        }

        $path = $this->testFilePath($record);
        $this->ensureDirectory(dirname($path));
        file_put_contents($path, $this->testPhp($record, $fixturePath, $providerCanSign));

        return $path;
    }

    private function testPhp(CaptureRecord $record, string $fixturePath, bool $providerCanSign): string
    {
        $headersExpression = $providerCanSign ? '$fixture->freshSignedHeaders()' : '$fixture->headers()';
        $description = $record->eventType !== null
            ? "handles {$record->provider} {$record->eventType}"
            : "handles {$record->provider} captured traffic";

        return "<?php\n\nuse Oxhq\\Preview\\Testing\\PreviewFixture;\n\n"
            ."it(".$this->exportString($description).", function () {\n"
            ."    // Preconditions: the target app route, database state, fakes, and auth context must match this capture.\n"
            ."    \$fixture = PreviewFixture::load(".$this->exportString($fixturePath).");\n"
            ."    \$headers = {$headersExpression};\n\n"
            ."    \$this->call(\n"
            ."        \$fixture->requestMethod(),\n"
            ."        \$fixture->endpointPath(),\n"
            ."        [],\n"
            ."        [],\n"
            ."        [],\n"
            ."        \$fixture->serverHeaders(\$headers),\n"
            ."        \$fixture->rawBody(),\n"
            ."    )->assertStatus(\$fixture->expectedStatus());\n"
            ."});\n";
    }

    private function testFilePath(CaptureRecord $record): string
    {
        $name = $record->metadata['fixture_name'] ?? $record->eventType ?? $record->id;

        return $this->testRoot().DIRECTORY_SEPARATOR.'Preview'.DIRECTORY_SEPARATOR.$this->safeSegment($record->provider.'-'.$name).'Test.php';
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

    private function safeSegment(string $value): string
    {
        return preg_replace('/[^A-Za-z0-9_.-]/', '-', (string) $value) ?: 'preview-capture';
    }
}
