<?php

declare(strict_types=1);

namespace Oxhq\Preview\Commands;

use Illuminate\Console\Command;
use Oxhq\Preview\Capture\CaptureRecord;
use Oxhq\Preview\Capture\CaptureRepository;
use Oxhq\Preview\Core\ProviderRegistry;
use Throwable;

final class CaptureDoctorCommand extends Command
{
    protected $signature = 'preview:capture:doctor
        {--capture= : Capture ID}
        {--json : Emit machine-readable JSON output}';

    protected $description = 'Verify stored Preview captures are internally consistent.';

    public function __construct(
        private readonly CaptureRepository $captures,
        private readonly ProviderRegistry $providers,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $capture = $this->option('capture');
        $paths = is_string($capture) && $capture !== ''
            ? [$this->captures->metadataFilePath($capture)]
            : $this->captures->metadataFilePaths();

        if (is_string($capture) && $capture !== '' && ! is_file($paths[0])) {
            $this->error("Capture [{$capture}] was not found.");

            return self::FAILURE;
        }

        $rows = array_map(fn (string $path): array => $this->diagnose($path), $paths);
        $failed = array_filter($rows, fn (array $row): bool => $row['valid'] === false) !== [];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $failed ? self::FAILURE : self::SUCCESS;
        }

        if ($rows === []) {
            $this->line('No captures found.');

            return self::SUCCESS;
        }

        $this->line('Preview capture diagnostics:');

        foreach ($rows as $row) {
            $this->line(sprintf(
                ' - %s (%s): %s',
                $row['id'],
                $row['provider'] ?? 'unknown provider',
                $row['valid'] ? 'valid' : 'invalid',
            ));

            foreach ($row['errors'] as $error) {
                $this->line('   Error: '.$error);
            }

            foreach ($row['warnings'] as $warning) {
                $this->line('   Warning: '.$warning);
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array{id: string, provider: ?string, valid: bool, errors: list<string>, warnings: list<string>}
     */
    private function diagnose(string $metadataPath): array
    {
        $id = basename(dirname($metadataPath));
        $provider = null;
        $errors = [];
        $warnings = [];

        $json = @file_get_contents($metadataPath);

        if ($json === false) {
            return $this->row($id, null, ['Metadata could not be read.'], []);
        }

        $data = json_decode($json, true);

        if (! is_array($data)) {
            return $this->row($id, null, ['Metadata could not be decoded.'], []);
        }

        $provider = isset($data['provider']) ? (string) $data['provider'] : null;

        try {
            $record = CaptureRecord::fromArray($data);
        } catch (Throwable) {
            return $this->row($id, $provider, ['Metadata is incomplete or invalid.'], []);
        }

        if ($record->id !== $id) {
            $warnings[] = 'Metadata id does not match its capture directory.';
        }

        if (! $this->providerIsRegistered($record->provider)) {
            $errors[] = "Provider [{$record->provider}] is not registered.";
        }

        if (! $this->fileCanBeRead($record->rawBodyPath)) {
            $errors[] = 'Raw body file could not be read.';
        }

        $rawHeaders = $this->decodedRawHeaders($record);

        if ($rawHeaders === null) {
            $errors[] = 'Raw headers file could not be read.';
        } elseif ($rawHeaders === false) {
            $errors[] = 'Raw headers file could not be decoded.';
        }

        foreach ($this->unredactedSensitiveMetadataHeaders($record->headers) as $header) {
            $errors[] = "Metadata header [{$header}] contains unredacted sensitive data.";
        }

        return $this->row($record->id, $record->provider, $errors, $warnings);
    }

    private function providerIsRegistered(string $provider): bool
    {
        return array_key_exists(strtolower($provider), $this->providers->all());
    }

    private function fileCanBeRead(string $path): bool
    {
        return is_file($path) && is_readable($path) && @file_get_contents($path) !== false;
    }

    private function decodedRawHeaders(CaptureRecord $record): array|bool|null
    {
        if ($record->rawHeadersPath === null || ! is_file($record->rawHeadersPath) || ! is_readable($record->rawHeadersPath)) {
            return null;
        }

        $json = @file_get_contents($record->rawHeadersPath);

        if ($json === false) {
            return null;
        }

        $headers = json_decode($json, true);

        return is_array($headers) ? $headers : false;
    }

    /**
     * @param array<string, mixed> $headers
     * @return list<string>
     */
    private function unredactedSensitiveMetadataHeaders(array $headers): array
    {
        $sensitive = array_map(
            fn (mixed $header): string => strtolower((string) $header),
            (array) config('preview.redact_headers', []),
        );
        $unredacted = [];

        foreach ($headers as $name => $value) {
            if (! in_array(strtolower((string) $name), $sensitive, true)) {
                continue;
            }

            if ($value !== '[redacted]') {
                $unredacted[] = (string) $name;
            }
        }

        return $unredacted;
    }

    /**
     * @param list<string> $errors
     * @param list<string> $warnings
     * @return array{id: string, provider: ?string, valid: bool, errors: list<string>, warnings: list<string>}
     */
    private function row(string $id, ?string $provider, array $errors, array $warnings): array
    {
        return [
            'id' => $id,
            'provider' => $provider,
            'valid' => $errors === [],
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }
}
