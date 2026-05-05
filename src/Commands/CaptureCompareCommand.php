<?php

declare(strict_types=1);

namespace Oxhq\Preview\Commands;

use Illuminate\Console\Command;
use Oxhq\Preview\Capture\CaptureRecord;
use Oxhq\Preview\Capture\CaptureRepository;
use Throwable;

final class CaptureCompareCommand extends Command
{
    protected $signature = 'preview:capture:compare
        {left : Left capture ID}
        {right : Right capture ID}
        {--json : Emit machine-readable JSON output}';

    protected $description = 'Compare two Preview captures using safe metadata and file hashes.';

    public function __construct(private readonly CaptureRepository $captures)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $left = $this->captures->find((string) $this->argument('left'));
            $right = $this->captures->find((string) $this->argument('right'));
            $result = $this->compare($left, $right);
        } catch (Throwable $exception) {
            if ((bool) $this->option('json')) {
                $this->line($this->json([
                    'same' => false,
                    'error' => $exception->getMessage(),
                ]));
            } else {
                $this->error($exception->getMessage());
            }

            return self::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $this->line($this->json($result));

            return $result['same'] ? self::SUCCESS : self::FAILURE;
        }

        if ($result['same']) {
            $this->info("Captures [{$left->id}] and [{$right->id}] are the same.");
        } else {
            $this->warn("Captures [{$left->id}] and [{$right->id}] differ.");
        }

        $this->line('Same: '.$this->formatNames($result['same_fields']));
        $this->line('Differences: '.$this->formatNames($result['differences']));

        return $result['same'] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array{
     *     left: string,
     *     right: string,
     *     same: bool,
     *     same_fields: list<string>,
     *     differences: list<string>,
     *     fields: array<string, array{same: bool, left: mixed, right: mixed}>
     * }
     */
    private function compare(CaptureRecord $left, CaptureRecord $right): array
    {
        $fields = [
            'provider' => [$left->provider, $right->provider],
            'event_type' => [$left->eventType, $right->eventType],
            'method' => [$left->method, $right->method],
            'path' => [$left->path, $right->path],
            'query' => [$left->query, $right->query],
            'header_keys' => [$this->headerKeys($left), $this->headerKeys($right)],
            'verified' => [$left->verified, $right->verified],
            'raw_body_sha256' => [$this->sha256($left->rawBodyPath), $this->sha256($right->rawBodyPath)],
            'raw_headers_sha256' => [$this->sha256($left->rawHeadersPath), $this->sha256($right->rawHeadersPath)],
        ];

        $compared = [];
        $sameFields = [];
        $differences = [];

        foreach ($fields as $name => [$leftValue, $rightValue]) {
            $same = $leftValue === $rightValue;
            $compared[$name] = [
                'same' => $same,
                'left' => $leftValue,
                'right' => $rightValue,
            ];

            if ($same) {
                $sameFields[] = $name;
            } else {
                $differences[] = $name;
            }
        }

        return [
            'left' => $left->id,
            'right' => $right->id,
            'same' => $differences === [],
            'same_fields' => $sameFields,
            'differences' => $differences,
            'fields' => $compared,
        ];
    }

    /**
     * @return list<string>
     */
    private function headerKeys(CaptureRecord $record): array
    {
        $headers = $record->rawHeadersPath === null ? $record->headers : $record->rawHeaders();
        $keys = array_map('strval', array_keys($headers));
        sort($keys, SORT_STRING);

        return array_values($keys);
    }

    private function sha256(?string $path): ?string
    {
        if ($path === null || ! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $hash = hash_file('sha256', $path);

        return $hash === false ? null : $hash;
    }

    /**
     * @param list<string> $names
     */
    private function formatNames(array $names): string
    {
        return $names === [] ? 'none' : implode(', ', $names);
    }

    /**
     * @param array<string, mixed> $value
     */
    private function json(array $value): string
    {
        return (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
