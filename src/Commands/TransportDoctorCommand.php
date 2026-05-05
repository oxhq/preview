<?php

declare(strict_types=1);

namespace Oxhq\Preview\Commands;

use Illuminate\Console\Command;
use Oxhq\Preview\Core\Transport\TransportRegistry;
use Symfony\Component\Process\Process;

final class TransportDoctorCommand extends Command
{
    protected $signature = 'preview:transport:doctor {--json : Output diagnostics as JSON}';

    protected $description = 'Diagnose configured Laravel Preview tunnel transports without opening tunnels.';

    public function __construct(private readonly TransportRegistry $transports)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $rows = $this->diagnostics();

        if ((bool) $this->option('json')) {
            $this->line(json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($rows === []) {
            $this->line('No transports configured.');

            return self::SUCCESS;
        }

        $this->line('Preview transport diagnostics:');

        foreach ($rows as $row) {
            $this->line(sprintf(' - %s: %s', $row['name'], $row['class']));

            if ($row['binary'] === null) {
                $this->line('   Binary: not configured');

                continue;
            }

            $this->line('   Binary: '.$row['binary']);
            $this->line($this->discoverableLine($row));
        }

        return self::SUCCESS;
    }

    /** @return list<array{name: string, class: class-string|non-empty-string, binary: ?string, binary_found: bool, binary_path: ?string, binary_source: ?string}> */
    private function diagnostics(): array
    {
        $rows = [];

        foreach ($this->transports->all() as $name => $transport) {
            $binary = $this->configuredBinary($name);
            $resolution = $binary === null ? null : $this->resolveBinary($binary);

            $rows[] = [
                'name' => $name,
                'class' => $transport::class,
                'binary' => $binary,
                'binary_found' => $resolution !== null,
                'binary_path' => $resolution['path'] ?? null,
                'binary_source' => $resolution['source'] ?? null,
            ];
        }

        return $rows;
    }

    private function configuredBinary(string $transportName): ?string
    {
        $binaries = (array) config('preview.transport_binaries', []);
        $key = str_replace('-', '_', $transportName);
        $binary = $binaries[$key] ?? null;

        if (! is_string($binary) || trim($binary) === '') {
            return null;
        }

        return $binary;
    }

    /** @return null|array{path: string, source: string} */
    private function resolveBinary(string $binary): ?array
    {
        if ($this->isAbsolutePath($binary)) {
            return is_file($binary)
                ? ['path' => $binary, 'source' => 'absolute']
                : null;
        }

        return $this->resolveBinaryOnPath($binary);
    }

    private function isAbsolutePath(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        if (DIRECTORY_SEPARATOR === '\\') {
            return preg_match('/^[a-zA-Z]:[\\\\\\/]/', $path) === 1
                || str_starts_with($path, '\\\\');
        }

        return str_starts_with($path, '/');
    }

    /** @return null|array{path: string, source: string} */
    private function resolveBinaryOnPath(string $binary): ?array
    {
        $process = DIRECTORY_SEPARATOR === '\\'
            ? new Process(['where.exe', $binary])
            : Process::fromShellCommandline('command -v -- '.escapeshellarg($binary));

        $process->setTimeout(2);
        $process->run();

        if (! $process->isSuccessful()) {
            return null;
        }

        $path = strtok(trim($process->getOutput()), PHP_EOL);

        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        return ['path' => trim($path), 'source' => 'path'];
    }

    /** @param array{binary_found: bool, binary_path: ?string, binary_source: ?string} $row */
    private function discoverableLine(array $row): string
    {
        if (! $row['binary_found']) {
            return '   Discoverable: no';
        }

        $source = $row['binary_source'] === 'absolute' ? 'absolute path' : 'PATH';
        $path = $row['binary_path'];

        if (! is_string($path) || $path === '' || $row['binary_source'] === 'absolute') {
            return sprintf('   Discoverable: yes (%s)', $source);
        }

        return sprintf('   Discoverable: yes (%s: %s)', $source, $path);
    }
}
