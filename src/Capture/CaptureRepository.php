<?php

declare(strict_types=1);

namespace Oxhq\Preview\Capture;

use Oxhq\Preview\Core\CaptureId;
use Oxhq\Preview\Core\GitIgnoreGuard;
use Oxhq\Preview\Core\RedactionPolicy;
use Oxhq\Preview\Providers\PreviewProvider;
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

        $this->gitIgnoreGuard()->ensureIgnored($this->storageRoot());
        $this->ensureDirectory($directory);
        file_put_contents($rawBodyPath, $request->rawBody);

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
            ],
        );

        $encoded = json_encode($record->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($encoded === false) {
            throw new RuntimeException("Capture [{$id}] metadata could not be encoded.");
        }

        file_put_contents($this->metadataPath($id), $encoded.PHP_EOL);

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

    private function safeSegment(string $value): string
    {
        return preg_replace('/[^A-Za-z0-9_.-]/', '-', $value) ?: 'capture';
    }
}
