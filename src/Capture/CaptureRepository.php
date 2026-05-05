<?php

declare(strict_types=1);

namespace Oxhq\Preview\Capture;

use DateTimeInterface;
use FilesystemIterator;
use Oxhq\Preview\Core\CaptureId;
use Oxhq\Preview\Core\GitIgnoreGuard;
use Oxhq\Preview\Core\RedactionPolicy;
use Oxhq\Preview\Providers\PreviewProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class CaptureRepository
{
    public function __construct(
        private readonly ?string $storagePath = null,
        private readonly ?RedactionPolicy $redactionPolicy = null,
        private readonly ?CaptureId $captureId = null,
        private readonly ?GitIgnoreGuard $gitIgnoreGuard = null,
    ) {
    }

    public function store(PreviewRequest $request, PreviewProvider $provider): CaptureRecord
    {
        $verification = $this->verificationFrom($provider->verify($request));
        $request = $request->withVerification($verification['verified'], $verification['message']);
        $id = $this->newCaptureId();
        $directory = $this->captureDirectory($id);
        $rawBodyPath = $directory.DIRECTORY_SEPARATOR.'body.raw';
        $rawHeadersPath = $directory.DIRECTORY_SEPARATOR.'headers.raw.json';

        $this->gitIgnoreGuard()->ensureIgnored($this->storageRoot());
        $this->ensureDirectory($directory);
        file_put_contents($rawBodyPath, $request->rawBody);
        $this->writeJson($rawHeadersPath, $request->headers, "Capture [{$id}] raw headers could not be encoded.");

        $record = new CaptureRecord(
            id: $id,
            provider: $provider->name(),
            eventType: $provider->eventType($request),
            method: $request->method,
            path: $request->path,
            query: $request->query,
            headers: $this->redactHeaders($request->headers),
            rawBodyPath: $rawBodyPath,
            capturedAt: $request->capturedAt,
            verified: $request->verified,
            verificationMessage: $request->verificationMessage,
            metadata: [
                'fixture_name' => $provider->fixtureName($request),
                'fixture_context' => $provider->fixtureContext($request),
            ],
            rawHeadersPath: $rawHeadersPath,
        );

        $this->writeJson($this->metadataPath($id), $record->toArray(), "Capture [{$id}] metadata could not be encoded.");

        return $record;
    }

    public function find(string $id): CaptureRecord
    {
        $path = $this->metadataPath($id);

        if (! is_file($path)) {
            throw new RuntimeException("Capture [{$id}] was not found.");
        }

        $json = file_get_contents($path);
        $data = $json === false ? null : json_decode($json, true);

        if (! is_array($data)) {
            throw new RuntimeException("Capture [{$id}] metadata could not be read.");
        }

        return CaptureRecord::fromArray($data);
    }

    /**
     * @return list<CaptureRecord>
     */
    public function all(): array
    {
        $root = $this->storageRoot();

        if (! is_dir($root)) {
            return [];
        }

        $records = [];

        foreach (glob($root.DIRECTORY_SEPARATOR.'*'.DIRECTORY_SEPARATOR.'metadata.json') ?: [] as $metadataPath) {
            $json = file_get_contents($metadataPath);
            $data = $json === false ? null : json_decode($json, true);

            if (is_array($data)) {
                $records[] = CaptureRecord::fromArray($data);
            }
        }

        usort(
            $records,
            fn (CaptureRecord $left, CaptureRecord $right): int => $right->capturedAt <=> $left->capturedAt,
        );

        return $records;
    }

    /**
     * @return list<CaptureRecord>
     */
    public function pruneBefore(DateTimeInterface $cutoff, bool $dryRun = false): array
    {
        $records = array_values(array_filter(
            $this->all(),
            fn (CaptureRecord $record): bool => $record->capturedAt < $cutoff,
        ));

        if ($dryRun || $records === []) {
            return $records;
        }

        $root = realpath($this->storageRoot());

        if ($root === false || ! is_dir($root)) {
            throw new RuntimeException('Capture storage root could not be resolved.');
        }

        foreach ($records as $record) {
            $this->deleteCaptureDirectory($record->id, $root);
        }

        return $records;
    }

    private function newCaptureId(): string
    {
        if ($this->captureId !== null) {
            return $this->captureId->new();
        }

        return gmdate('YmdHis').'-'.bin2hex(random_bytes(5));
    }

    private function gitIgnoreGuard(): GitIgnoreGuard
    {
        return $this->gitIgnoreGuard ?? new GitIgnoreGuard();
    }

    private function metadataPath(string $id): string
    {
        return $this->captureDirectory($id).DIRECTORY_SEPARATOR.'metadata.json';
    }

    private function captureDirectory(string $id): string
    {
        return $this->storageRoot().DIRECTORY_SEPARATOR.$this->safeSegment($id);
    }

    private function storageRoot(): string
    {
        if ($this->storagePath !== null) {
            return rtrim($this->storagePath, DIRECTORY_SEPARATOR);
        }

        $configured = function_exists('config') ? config('preview.storage_path') : null;

        if (is_string($configured) && $configured !== '') {
            return rtrim($configured, DIRECTORY_SEPARATOR);
        }

        if (function_exists('storage_path')) {
            return storage_path('preview/captures');
        }

        return getcwd().DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'preview'.DIRECTORY_SEPARATOR.'captures';
    }

    /**
     * @param array<string, mixed> $headers
     * @return array<string, mixed>
     */
    private function redactHeaders(array $headers): array
    {
        if ($this->redactionPolicy !== null) {
            return $this->redactionPolicy->redactHeaders($headers);
        }

        return $headers;
    }

    /**
     * @return array{verified: bool, message: string|null}
     */
    private function verificationFrom(mixed $result): array
    {
        if (is_bool($result)) {
            return ['verified' => $result, 'message' => null];
        }

        foreach (['verified', 'valid', 'success', 'passed'] as $property) {
            if (is_object($result) && property_exists($result, $property)) {
                $message = property_exists($result, 'message') ? $result->message : null;

                return [
                    'verified' => (bool) $result->{$property},
                    'message' => is_string($message) ? $message : null,
                ];
            }
        }

        foreach (['verified', 'isVerified', 'valid', 'isValid', 'success', 'passed'] as $method) {
            if (is_object($result) && method_exists($result, $method)) {
                $verified = (bool) $result->{$method}();
                $message = method_exists($result, 'message') ? $result->message() : null;

                return ['verified' => $verified, 'message' => is_string($message) ? $message : null];
            }
        }

        return ['verified' => false, 'message' => 'Provider returned an unsupported verification result.'];
    }

    private function ensureDirectory(string $path): void
    {
        if (! is_dir($path) && ! mkdir($path, 0775, true) && ! is_dir($path)) {
            throw new RuntimeException("Directory [{$path}] could not be created.");
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeJson(string $path, array $data, string $errorMessage): void
    {
        $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($encoded === false) {
            throw new RuntimeException($errorMessage);
        }

        file_put_contents($path, $encoded.PHP_EOL);
    }

    private function safeSegment(string $value): string
    {
        return preg_replace('/[^A-Za-z0-9_.-]/', '-', $value) ?: 'capture';
    }

    private function deleteCaptureDirectory(string $id, string $resolvedRoot): void
    {
        $directory = $this->captureDirectory($id);
        $resolvedDirectory = realpath($directory);

        if ($resolvedDirectory === false || ! is_dir($resolvedDirectory)) {
            throw new RuntimeException("Capture [{$id}] directory could not be resolved.");
        }

        if ($this->samePath($resolvedDirectory, $resolvedRoot)) {
            throw new RuntimeException("Capture [{$id}] directory resolved to the configured capture storage root.");
        }

        $this->assertInsideStorageRoot($resolvedDirectory, $resolvedRoot, "Capture [{$id}] directory");

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($resolvedDirectory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $path = $item->getPathname();

            if (! $item->isLink()) {
                $resolvedPath = realpath($path);

                if ($resolvedPath === false) {
                    throw new RuntimeException("Capture [{$id}] path [{$path}] could not be resolved.");
                }

                $this->assertInsideStorageRoot($resolvedPath, $resolvedRoot, "Capture [{$id}] path [{$path}]");
            }

            if ($item->isDir() && ! $item->isLink()) {
                rmdir($path);
            } else {
                unlink($path);
            }
        }

        rmdir($resolvedDirectory);
    }

    private function assertInsideStorageRoot(string $path, string $root, string $label): void
    {
        if (! $this->isInside($path, $root)) {
            throw new RuntimeException("{$label} is outside the configured capture storage root.");
        }
    }

    private function isInside(string $path, string $root): bool
    {
        $path = $this->normalizePath($path);
        $root = rtrim($this->normalizePath($root), DIRECTORY_SEPARATOR);

        if (DIRECTORY_SEPARATOR === '\\') {
            $path = strtolower($path);
            $root = strtolower($root);
        }

        return $path === $root || str_starts_with($path, $root.DIRECTORY_SEPARATOR);
    }

    private function samePath(string $left, string $right): bool
    {
        $left = rtrim($this->normalizePath($left), DIRECTORY_SEPARATOR);
        $right = rtrim($this->normalizePath($right), DIRECTORY_SEPARATOR);

        if (DIRECTORY_SEPARATOR === '\\') {
            $left = strtolower($left);
            $right = strtolower($right);
        }

        return $left === $right;
    }

    private function normalizePath(string $path): string
    {
        return str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }
}
