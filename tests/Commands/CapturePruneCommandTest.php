<?php

declare(strict_types=1);

namespace Oxhq\Preview\Tests\Commands;

use DateTimeImmutable;
use Illuminate\Support\Facades\Artisan;
use Oxhq\Preview\Capture\CaptureRecord;
use Oxhq\Preview\Capture\CaptureRepository;
use Oxhq\Preview\Capture\PreviewRequest;
use Oxhq\Preview\Providers\GenericProvider;
use Oxhq\Preview\Tests\TestCase;

final class CapturePruneCommandTest extends TestCase
{
    public function test_prune_requires_an_explicit_cutoff(): void
    {
        $this->artisan('preview:capture:prune')
            ->expectsOutput('Provide --before=YYYY-MM-DD before pruning captures.')
            ->assertExitCode(1);
    }

    public function test_prune_dry_run_lists_matching_capture_ids_without_deleting(): void
    {
        $repository = app(CaptureRepository::class);
        $old = $this->storeCaptureAt($repository, '/old', '2025-12-31T23:59:59+00:00');
        $new = $this->storeCaptureAt($repository, '/new', '2026-01-02T00:00:00+00:00');

        $this->artisan('preview:capture:prune', [
            '--before' => '2026-01-01',
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('Dry run: 1 capture would be pruned.')
            ->expectsOutputToContain($old->id)
            ->assertExitCode(0);

        $this->assertEqualsCanonicalizing([$old->id, $new->id], array_map(
            fn ($record): string => $record->id,
            $repository->all(),
        ));
    }

    public function test_prune_deletes_captures_older_than_the_cutoff_and_reports_json(): void
    {
        $repository = app(CaptureRepository::class);
        $old = $this->storeCaptureAt($repository, '/old', '2025-12-31T23:59:59+00:00');
        $onCutoff = $this->storeCaptureAt($repository, '/cutoff', '2026-01-01T00:00:00+00:00');

        $exitCode = Artisan::call('preview:capture:prune', [
            '--before' => '2026-01-01',
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $payload['count']);
        $this->assertFalse($payload['dry_run']);
        $this->assertSame([$old->id], $payload['ids']);
        $this->assertSame([$onCutoff->id], array_map(
            fn ($record): string => $record->id,
            $repository->all(),
        ));
    }

    public function test_prune_rejects_non_date_cutoffs(): void
    {
        $this->artisan('preview:capture:prune', [
            '--before' => 'yesterday',
        ])
            ->expectsOutput('The --before option must use YYYY-MM-DD.')
            ->assertExitCode(1);
    }

    private function storeCaptureAt(CaptureRepository $repository, string $path, string $capturedAt): CaptureRecord
    {
        return $repository->store(
            new PreviewRequest('generic', 'POST', $path, [], [], '{}', new DateTimeImmutable($capturedAt)),
            new GenericProvider(),
        );
    }
}
