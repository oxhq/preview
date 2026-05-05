<?php

declare(strict_types=1);

namespace Oxhq\Preview\Commands;

use Illuminate\Console\Command;
use Oxhq\Preview\Core\Transport\TransportRegistry;

final class TransportListCommand extends Command
{
    protected $signature = 'preview:transport:list {--json : Output transports as JSON}';

    protected $description = 'List configured Laravel Preview tunnel transports.';

    public function __construct(private readonly TransportRegistry $transports)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $transports = $this->transports->all();
        $rows = array_map(
            fn (string $name, object $transport): array => [
                'name' => $name,
                'class' => $transport::class,
            ],
            array_keys($transports),
            $transports,
        );

        if ((bool) $this->option('json')) {
            $this->line(json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($rows === []) {
            $this->line('No transports configured.');

            return self::SUCCESS;
        }

        $this->line('Preview transports:');

        foreach ($rows as $row) {
            $this->line(sprintf(' - %s: %s', $row['name'], $row['class']));
        }

        return self::SUCCESS;
    }
}
