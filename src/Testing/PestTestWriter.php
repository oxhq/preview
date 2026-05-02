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
        file_put_contents($path, $this->testPhp(
            $record,
            $this->fixtureReferenceExpression($fixturePath, dirname($path)),
            $providerCanSign,
        ));

        return $path;
    }

    private function testPhp(CaptureRecord $record, string $fixtureReferenceExpression, bool $providerCanSign): string
    {
        $headersExpression = $providerCanSign ? '$fixture->freshSignedHeaders()' : '$fixture->headers()';
        $description = $record->eventType !== null
            ? "handles {$record->provider} {$record->eventType}"
            : "handles {$record->provider} captured traffic";

        return "<?php\n\nuse Oxhq\\Preview\\Testing\\PreviewFixture;\n\n"
            ."it(".$this->exportString($description).", function () {\n"
            ."    // Preconditions: the target app route, database state, fakes, and auth context must match this capture.\n"
            ."    \$fixture = PreviewFixture::load({$fixtureReferenceExpression});\n"
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

    private function fixtureReferenceExpression(string $fixturePath, string $testDirectory): string
    {
        $relative = $this->relativePath($testDirectory, $fixturePath);

        if ($relative === null) {
            return $this->exportString($fixturePath);
        }

        return '__DIR__.'.$this->exportString('/'.$relative);
    }

    private function relativePath(string $fromDirectory, string $toPath): ?string
    {
        $from = $this->absolutePath($fromDirectory);
        $to = $this->absolutePath($toPath);
        $fromParts = $this->pathParts($from);
        $toParts = $this->pathParts($to);

        if ($fromParts === [] || $toParts === [] || strcasecmp($fromParts[0], $toParts[0]) !== 0) {
            return null;
        }

        $common = 0;
        $limit = min(count($fromParts), count($toParts));

        while ($common < $limit && $fromParts[$common] === $toParts[$common]) {
            $common++;
        }

        return implode('/', array_merge(
            array_fill(0, count($fromParts) - $common, '..'),
            array_slice($toParts, $common),
        ));
    }

    private function absolutePath(string $path): string
    {
        $real = realpath($path);
        $path = $real !== false ? $real : $path;

        if (preg_match('/^(?:[A-Za-z]:[\\\\\/]|[\\\\\/])/', $path) !== 1) {
            $path = getcwd().DIRECTORY_SEPARATOR.$path;
        }

        return str_replace('\\', '/', $path);
    }

    /**
     * @return list<string>
     */
    private function pathParts(string $path): array
    {
        return array_values(array_filter(
            explode('/', trim($path, '/')),
            static fn (string $part): bool => $part !== '',
        ));
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
