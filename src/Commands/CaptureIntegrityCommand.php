<?php

declare(strict_types=1);

namespace Oxhq\Preview\Commands;

use Illuminate\Console\Command;
use Oxhq\Preview\Capture\CaptureRepository;
use Throwable;

final class CaptureIntegrityCommand extends Command
{
    protected $signature = 'preview:capture:integrity
        {capture : Capture ID}
        {--json : Emit machine-readable JSON output}';

    protected $description = 'Verify stored Preview capture files and report safe hashes.';

    public function __construct(private readonly CaptureRepository $captures)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $captureId = (string) $this->argument('capture');
        $result = $this->inspect($captureId);

        if ((bool) $this->option('json')) {
            $this->line($this->json($result));

            return $result['ok'] ? self::SUCCESS : self::FAILURE;
        }

        if (! $result['ok']) {
            $this->error("Capture [{$captureId}] integrity failed.");

            foreach ($result['errors'] as $error) {
                $this->line('Error: '.$error);
            }
        } else {
            $this->info("Capture [{$captureId}] integrity passed.");
        }

        foreach ($result['files'] as $name => $file) {
            $this->line(sprintf(
                '%s: %s, %s bytes, sha256=%s',
                $name,
                $file['readable'] ? 'readable' : 'not readable',
                $file['bytes'] ?? 'unknown',
                $file['sha256'] ?? 'unknown',
            ));
        }

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array{
     *     capture_id: string,
     *     ok: bool,
     *     files: array<string, array{path: string|null, exists: bool, readable: bool, bytes: int|null, sha256: string|null}>,
     *     errors: list<string>
     * }
     */
    private function inspect(string $captureId): array
    {
        $metadataPath = $this->captures->metadataFilePath($captureId);
        $files = [
            'metadata' => $this->summarize($metadataPath),
        ];
        $errors = $this->errorsFor('Metadata', $files['metadata']);

        try {
            $record = $this->captures->find($captureId);
        } catch (Throwable $exception) {
            $errors[] = $exception->getMessage();

            return $this->result($captureId, $files, $errors);
        }

        $files['raw_body'] = $this->summarize($record->rawBodyPath);
        $files['raw_headers'] = $this->summarize($record->rawHeadersPath);

        $errors = [
            ...$errors,
            ...$this->errorsFor('Raw body', $files['raw_body']),
            ...$this->errorsFor('Raw headers', $files['raw_headers']),
        ];

        return $this->result($record->id, $files, $errors);
    }

    /**
     * @param array<string, array{path: string|null, exists: bool, readable: bool, bytes: int|null, sha256: string|null}> $files
     * @param list<string> $errors
     * @return array{
     *     capture_id: string,
     *     ok: bool,
     *     files: array<string, array{path: string|null, exists: bool, readable: bool, bytes: int|null, sha256: string|null}>,
     *     errors: list<string>
     * }
     */
    private function result(string $captureId, array $files, array $errors): array
    {
        return [
            'capture_id' => $captureId,
            'ok' => $errors === [],
            'files' => $files,
            'errors' => array_values(array_unique($errors)),
        ];
    }

    /**
     * @return array{path: string|null, exists: bool, readable: bool, bytes: int|null, sha256: string|null}
     */
    private function summarize(?string $path): array
    {
        if ($path === null || ! is_file($path) || ! is_readable($path)) {
            return [
                'path' => $path,
                'exists' => $path !== null && is_file($path),
                'readable' => false,
                'bytes' => null,
                'sha256' => null,
            ];
        }

        $bytes = filesize($path);
        $sha256 = hash_file('sha256', $path);

        if ($bytes === false || $sha256 === false) {
            return [
                'path' => $path,
                'exists' => true,
                'readable' => false,
                'bytes' => null,
                'sha256' => null,
            ];
        }

        return [
            'path' => $path,
            'exists' => true,
            'readable' => true,
            'bytes' => $bytes,
            'sha256' => $sha256,
        ];
    }

    /**
     * @param array{path: string|null, exists: bool, readable: bool, bytes: int|null, sha256: string|null} $file
     * @return list<string>
     */
    private function errorsFor(string $label, array $file): array
    {
        return $file['readable'] ? [] : ["{$label} file could not be read."];
    }

    /**
     * @param array<string, mixed> $value
     */
    private function json(array $value): string
    {
        return (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
