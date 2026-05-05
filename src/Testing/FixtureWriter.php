<?php

declare(strict_types=1);

namespace Oxhq\Preview\Testing;

use Oxhq\Preview\Capture\CaptureRecord;
use Oxhq\Preview\Core\GitIgnoreGuard;
use Oxhq\Preview\Core\RedactionPolicy;
use RuntimeException;

final class FixtureWriter
{
    /** @var list<string> */
    private const SECRET_HEADER_NAMES = ['authorization', 'cookie', 'set-cookie'];

    public function __construct(
        private readonly ?string $fixturePath = null,
        private readonly ?RedactionPolicy $redactionPolicy = null,
        private readonly ?GitIgnoreGuard $gitIgnoreGuard = null,
    ) {
    }

    public function write(CaptureRecord $record, bool $providerCanSign = false): PreviewFixture
    {
        $directory = $this->fixtureDirectory($record);
        $payload = $this->payloadLocation($record);
        $headersPath = $directory.DIRECTORY_SEPARATOR.'headers.php';
        $fixturePath = $directory.DIRECTORY_SEPARATOR.'fixture.php';
        $manifestPath = $this->manifestPath($record);
        $safeHeaders = $this->safeHeaders($record->headers);

        $this->ensureDirectory($directory);
        $this->ensureDirectory(dirname($payload['path']));

        if ($payload['local_only']) {
            $this->gitIgnoreGuard()->ensureIgnored($this->fixtureRoot().DIRECTORY_SEPARATOR.'.local');
        }

        file_put_contents($payload['path'], $record->rawBody());
        file_put_contents($headersPath, "<?php\n\nreturn ".$this->exportArray($safeHeaders).";\n");
        file_put_contents($fixturePath, $this->fixturePhp($record, $providerCanSign, $payload['expression']));
        file_put_contents($manifestPath, $this->manifestJson($record, $providerCanSign, $payload['local_only'], $safeHeaders));

        return PreviewFixture::load($fixturePath);
    }

    public function fixturePath(CaptureRecord $record): string
    {
        return $this->fixtureDirectory($record).DIRECTORY_SEPARATOR.'fixture.php';
    }

    public function manifestPath(CaptureRecord $record): string
    {
        return $this->fixtureDirectory($record).DIRECTORY_SEPARATOR.'manifest.json';
    }

    private function fixturePhp(CaptureRecord $record, bool $providerCanSign, string $payloadExpression): string
    {
        return "<?php\n\nuse Oxhq\\Preview\\Testing\\PreviewFixture;\n\nreturn PreviewFixture::provider(".$this->exportString($record->provider).")\n"
            ."    ->event(".$this->exportNullableString($record->eventType).")\n"
            ."    ->fixtureContext(".$this->exportArray($this->fixtureContext($record)).")\n"
            ."    ->endpoint(".$this->exportString($record->path).")\n"
            ."    ->method(".$this->exportString($record->method).")\n"
            ."    ->rawBody({$payloadExpression})\n"
            ."    ->headers(__DIR__.'/headers.php')\n"
            ."    ->signing(".$this->exportString($this->signingMode($providerCanSign)).")\n"
            ."    ->assertsOk();\n";
    }

    /**
     * @param array<string, mixed> $safeHeaders
     */
    private function manifestJson(CaptureRecord $record, bool $providerCanSign, bool $payloadLocalOnly, array $safeHeaders): string
    {
        $json = json_encode([
            'capture_id' => $record->id,
            'provider' => $record->provider,
            'event_type' => $record->eventType,
            'method' => strtoupper($record->method),
            'endpoint' => $record->path,
            'signing' => $this->signingMode($providerCanSign),
            'fixture_context' => $this->fixtureContext($record),
            'payload' => [
                'local_only' => $payloadLocalOnly,
            ],
            'headers' => $this->manifestHeaders($safeHeaders),
            'redacted_headers' => $this->redactedHeaderNames($record->headers, $safeHeaders),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw new RuntimeException("Fixture manifest for capture [{$record->id}] could not be encoded.");
        }

        return $json."\n";
    }

    private function signingMode(bool $providerCanSign): string
    {
        return $providerCanSign ? 'resign' : 'exact';
    }

    private function fixtureDirectory(CaptureRecord $record): string
    {
        $fixtureName = isset($record->metadata['fixture_name']) && is_string($record->metadata['fixture_name'])
            ? $record->metadata['fixture_name']
            : ($record->eventType ?: $record->id);

        return $this->fixtureRoot().DIRECTORY_SEPARATOR.$this->safeSegment($record->provider).DIRECTORY_SEPARATOR.$this->safeSegment($fixtureName);
    }

    /**
     * @return array{path: string, expression: string, local_only: bool}
     */
    private function payloadLocation(CaptureRecord $record): array
    {
        [$provider, $fixture] = $this->fixtureSegments($record);

        if ($this->payloadShouldBeLocalOnly($record)) {
            $relative = '../../.local/'.$provider.'/'.$fixture.'/payload.json';

            return [
                'path' => $this->fixtureRoot().DIRECTORY_SEPARATOR.'.local'.DIRECTORY_SEPARATOR.$provider.DIRECTORY_SEPARATOR.$fixture.DIRECTORY_SEPARATOR.'payload.json',
                'expression' => "__DIR__.".$this->exportString('/'.$relative),
                'local_only' => true,
            ];
        }

        return [
            'path' => $this->fixtureDirectory($record).DIRECTORY_SEPARATOR.'payload.json',
            'expression' => "__DIR__.'/payload.json'",
            'local_only' => false,
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function fixtureSegments(CaptureRecord $record): array
    {
        $fixtureName = isset($record->metadata['fixture_name']) && is_string($record->metadata['fixture_name'])
            ? $record->metadata['fixture_name']
            : ($record->eventType ?: $record->id);

        return [$this->safeSegment($record->provider), $this->safeSegment($fixtureName)];
    }

    private function payloadShouldBeLocalOnly(CaptureRecord $record): bool
    {
        foreach ($record->headers as $name => $value) {
            if ($this->isSecretHeaderName((string) $name)) {
                return true;
            }

            if ($this->containsRedactedValue($value)) {
                return true;
            }
        }

        return false;
    }

    private function containsRedactedValue(mixed $value): bool
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                if ($this->containsRedactedValue($item)) {
                    return true;
                }
            }

            return false;
        }

        return is_string($value) && $value === '[redacted]';
    }

    /**
     * @return array<string, mixed>
     */
    private function fixtureContext(CaptureRecord $record): array
    {
        $context = $record->metadata['fixture_context'] ?? [];

        return is_array($context) ? $context : [];
    }

    private function fixtureRoot(): string
    {
        if ($this->fixturePath !== null) {
            return rtrim($this->fixturePath, DIRECTORY_SEPARATOR);
        }

        $configured = function_exists('config') ? config('preview.fixture_path') : null;

        if (is_string($configured) && $configured !== '') {
            return rtrim($configured, DIRECTORY_SEPARATOR);
        }

        return getcwd().DIRECTORY_SEPARATOR.'tests'.DIRECTORY_SEPARATOR.'Fixtures'.DIRECTORY_SEPARATOR.'Preview';
    }

    /**
     * @param array<string, mixed> $headers
     * @return array<string, mixed>
     */
    private function safeHeaders(array $headers): array
    {
        $headers = $this->redactionPolicy !== null ? $this->redactionPolicy->redactHeaders($headers) : $headers;

        foreach (array_keys($headers) as $name) {
            if ($this->isSecretHeaderName((string) $name)) {
                unset($headers[$name]);
            }
        }

        return $headers;
    }

    /**
     * @param array<string, mixed> $headers
     * @return array<string, mixed>
     */
    private function manifestHeaders(array $headers): array
    {
        foreach ($headers as $name => $value) {
            if ($this->containsRedactedValue($value)) {
                unset($headers[$name]);
            }
        }

        return $headers;
    }

    /**
     * @param array<string, mixed> $originalHeaders
     * @param array<string, mixed> $safeHeaders
     * @return list<string>
     */
    private function redactedHeaderNames(array $originalHeaders, array $safeHeaders): array
    {
        $redacted = [];

        foreach (array_keys($originalHeaders) as $name) {
            if ($this->isSecretHeaderName((string) $name)) {
                $redacted[(string) $name] = (string) $name;
            }
        }

        foreach ($safeHeaders as $name => $value) {
            if ($this->containsRedactedValue($value)) {
                $redacted[(string) $name] = (string) $name;
            }
        }

        return array_values($redacted);
    }

    private function isSecretHeaderName(string $name): bool
    {
        return in_array(strtolower($name), self::SECRET_HEADER_NAMES, true);
    }

    /**
     * @param array<string, mixed> $array
     */
    private function exportArray(array $array): string
    {
        return var_export($array, true);
    }

    private function exportNullableString(?string $value): string
    {
        return $value === null ? 'null' : $this->exportString($value);
    }

    private function exportString(string $value): string
    {
        return var_export($value, true);
    }

    private function ensureDirectory(string $path): void
    {
        if (! is_dir($path) && ! mkdir($path, 0775, true) && ! is_dir($path)) {
            throw new RuntimeException("Directory [{$path}] could not be created.");
        }
    }

    private function gitIgnoreGuard(): GitIgnoreGuard
    {
        return $this->gitIgnoreGuard ?? new GitIgnoreGuard();
    }

    private function safeSegment(string $value): string
    {
        return preg_replace('/[^A-Za-z0-9_.-]/', '-', $value) ?: 'fixture';
    }
}
