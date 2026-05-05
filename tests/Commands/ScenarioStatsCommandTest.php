<?php

declare(strict_types=1);

namespace Oxhq\Preview\Tests\Commands;

use Illuminate\Support\Facades\Artisan;
use Oxhq\Preview\Tests\TestCase;

final class ScenarioStatsCommandTest extends TestCase
{
    public function test_it_outputs_scenario_inventory_as_json_without_running_scenarios(): void
    {
        $path = $this->scenarioPath('json');
        config()->set('preview.scenario_path', $path);

        $this->writeScenario($path, 'renewal.php', <<<'PHP'
<?php

use Oxhq\Preview\Scenario\Scenario;
use Oxhq\Preview\Tests\Commands\ScenarioStatsProbeSeeder;

return new Scenario(
    name: 'renewal',
    seed: ScenarioStatsProbeSeeder::class,
    routes: ['billing.portal', 'billing.invoice'],
    routeContext: [
        'billing.portal' => [
            'fakes' => ['mail'],
        ],
    ],
    captures: ['cap_one'],
    fakes: ['queue'],
    notes: 'Local renewal flow.',
);
PHP);

        $this->writeScenario($path, 'webhook-only.php', <<<'PHP'
<?php

use Oxhq\Preview\Scenario\Scenario;

return new Scenario(
    name: 'webhook-only',
    captures: ['cap_two', 'cap_three'],
    fakes: ['events'],
);
PHP);

        $exitCode = Artisan::call('preview:scenario:stats', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(0, $exitCode);
        self::assertTrue($payload['valid']);
        self::assertSame($path, $payload['scenario_path']);
        self::assertSame(2, $payload['total']);
        self::assertSame(1, $payload['with_seed']);
        self::assertSame(1, $payload['with_routes']);
        self::assertSame(2, $payload['with_captures']);
        self::assertSame(2, $payload['with_fakes']);
        self::assertSame(2, $payload['route_count']);
        self::assertSame(3, $payload['capture_count']);
        self::assertSame([
            'events' => 1,
            'mail' => 1,
            'queue' => 1,
        ], $payload['fake_counts']);
        self::assertSame('renewal', $payload['scenarios'][0]['name']);
        self::assertSame(2, $payload['scenarios'][0]['route_count']);
        self::assertSame(1, $payload['scenarios'][0]['capture_count']);
        self::assertSame(2, $payload['scenarios'][0]['fake_count']);
        self::assertTrue($payload['scenarios'][0]['has_notes']);
        self::assertSame(0, ScenarioStatsProbeSeeder::$runs);
    }

    public function test_it_prints_text_scenario_inventory(): void
    {
        $path = $this->scenarioPath('text');
        config()->set('preview.scenario_path', $path);

        $this->writeScenario($path, 'checkout.php', <<<'PHP'
<?php

use Oxhq\Preview\Scenario\Scenario;

return new Scenario(
    name: 'checkout',
    routes: ['checkout.success'],
    captures: ['cap_checkout'],
    fakes: ['http'],
);
PHP);

        $this->artisan('preview:scenario:stats')
            ->expectsOutput('Scenario inventory:')
            ->expectsOutput('Scenario path: '.$path)
            ->expectsOutput('Total scenarios: 1')
            ->expectsOutput('With seed: 0')
            ->expectsOutput('With routes: 1')
            ->expectsOutput('With captures: 1')
            ->expectsOutput('With fakes: 1')
            ->expectsOutput('Total routes: 1')
            ->expectsOutput('Total captures: 1')
            ->expectsOutputToContain('checkout')
            ->assertExitCode(0);
    }

    public function test_it_reports_invalid_inventory_without_throwing(): void
    {
        $path = $this->scenarioPath('invalid');
        config()->set('preview.scenario_path', $path);

        $this->writeScenario($path, 'invalid.php', <<<'PHP'
<?php

return null;
PHP);

        $exitCode = Artisan::call('preview:scenario:stats', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(0, $exitCode);
        self::assertFalse($payload['valid']);
        self::assertSame($path, $payload['scenario_path']);
        self::assertSame(0, $payload['total']);
        self::assertStringContainsString('must return an instance', $payload['issue']);

        $this->artisan('preview:scenario:stats')
            ->expectsOutput('Scenario inventory is invalid.')
            ->expectsOutput('Scenario path: '.$path)
            ->expectsOutputToContain('Issue: Scenario file')
            ->expectsOutput('Total scenarios: 0')
            ->assertExitCode(0);
    }

    private function scenarioPath(string $namespace): string
    {
        return sys_get_temp_dir().'/preview-tests/scenario-stats/'.$namespace.'/'.spl_object_id($this);
    }

    private function writeScenario(string $path, string $name, string $contents): void
    {
        if (! is_dir($path)) {
            mkdir($path, 0775, true);
        }

        file_put_contents($path.'/'.$name, $contents);
    }
}

final class ScenarioStatsProbeSeeder
{
    public static int $runs = 0;
}
