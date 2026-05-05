<?php

declare(strict_types=1);

namespace Oxhq\Preview\Tests\Commands;

use Illuminate\Database\Seeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Route;
use Oxhq\Preview\Capture\CaptureRepository;
use Oxhq\Preview\Capture\HttpReplayDispatcher;
use Oxhq\Preview\Capture\PreviewRequest;
use Oxhq\Preview\Capture\ReplayResult;
use Oxhq\Preview\Providers\GenericHmacProvider;
use Oxhq\Preview\Providers\GenericProvider;
use Oxhq\Preview\Tests\TestCase;

final class ScenarioReplayCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CommandRecordingScenarioSeeder::$runs = 0;
    }

    public function test_preview_scenario_replay_runs_seed_replays_exact_captures_and_routes(): void
    {
        $path = $this->scenarioPath();
        $this->app['config']->set('preview.scenario_path', $path);
        $capture = $this->storeGenericCapture('/webhooks/orders');

        Route::get('/checkout/show', fn (): string => 'checkout')
            ->name('checkout.show');

        $this->writeScenario($path, 'checkout.php', sprintf(<<<'PHP'
<?php

use Oxhq\Preview\Scenario\Scenario;
use Oxhq\Preview\Tests\Commands\CommandRecordingScenarioSeeder;

return new Scenario(
    name: 'checkout-flow',
    seed: CommandRecordingScenarioSeeder::class,
    routes: ['checkout.show'],
    captures: ['%s'],
);
PHP, $capture->id));

        $this->artisan('preview:scenario:replay', [
            'scenario' => 'checkout-flow',
            '--exact' => true,
        ])
            ->expectsOutput('Scenario replay ready for [checkout-flow] using [exact].')
            ->expectsOutput('Seed: '.CommandRecordingScenarioSeeder::class)
            ->expectsOutput("Capture: {$capture->id} POST /webhooks/orders")
            ->expectsOutput('Route: checkout.show HTTP 200')
            ->expectsOutput('Summary: seed=1 captures=1 dispatches=0 routes=1')
            ->assertExitCode(0);

        $this->assertSame(1, CommandRecordingScenarioSeeder::$runs);
    }

    public function test_preview_scenario_replay_can_emit_exact_mode_json(): void
    {
        $path = $this->scenarioPath();
        $this->app['config']->set('preview.scenario_path', $path);
        $capture = $this->storeGenericCapture('/webhooks/orders');

        Route::get('/checkout/show', fn (): string => 'checkout')
            ->name('checkout.show');

        $this->writeScenario($path, 'checkout-json.php', sprintf(<<<'PHP'
<?php

use Oxhq\Preview\Scenario\Scenario;
use Oxhq\Preview\Tests\Commands\CommandRecordingScenarioSeeder;

return new Scenario(
    name: 'checkout-json-flow',
    seed: CommandRecordingScenarioSeeder::class,
    routes: ['checkout.show'],
    captures: ['%s'],
);
PHP, $capture->id));

        [$exitCode, $output] = $this->runReplayJson([
            'scenario' => 'checkout-json-flow',
            '--exact' => true,
            '--json' => true,
        ]);

        $payload = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertSame('checkout-json-flow', $payload['scenario']);
        $this->assertSame('exact', $payload['mode']);
        $this->assertSame(CommandRecordingScenarioSeeder::class, $payload['seed']);
        $this->assertTrue($payload['successful']);
        $this->assertNull($payload['failure']);
        $this->assertSame([
            'seed' => 1,
            'captures' => 1,
            'dispatches' => 0,
            'routes' => 1,
        ], $payload['summary']);
        $this->assertSame($capture->id, $payload['captures'][0]['id']);
        $this->assertSame('POST', $payload['captures'][0]['method']);
        $this->assertSame('/webhooks/orders', $payload['captures'][0]['path']);
        $this->assertNull($payload['captures'][0]['dispatch']);
        $this->assertSame([], $payload['dispatches']);
        $this->assertSame('checkout.show', $payload['routes'][0]['name']);
        $this->assertSame(200, $payload['routes'][0]['status_code']);
        $this->assertSame('checkout', $payload['routes'][0]['output']);
        $this->assertTrue($payload['routes'][0]['successful']);
    }

    public function test_preview_scenario_replay_replays_captures_in_resign_mode(): void
    {
        $path = $this->scenarioPath();
        $this->app['config']->set('preview.scenario_path', $path);
        $capture = $this->storeHmacCapture('/webhooks/signed', '{"signed":true}');

        $this->writeScenario($path, 'signed.php', sprintf(<<<'PHP'
<?php

use Oxhq\Preview\Scenario\Scenario;

return new Scenario(
    name: 'signed-flow',
    captures: ['%s'],
);
PHP, $capture->id));

        $this->artisan('preview:scenario:replay', [
            'scenario' => 'signed-flow',
            '--resign' => true,
        ])
            ->expectsOutput('Scenario replay ready for [signed-flow] using [resign].')
            ->expectsOutput("Capture: {$capture->id} POST /webhooks/signed")
            ->expectsOutput('Summary: seed=0 captures=1 dispatches=0 routes=0')
            ->assertExitCode(0);
    }

    public function test_preview_scenario_replay_can_emit_resign_mode_json_with_dispatch_failure(): void
    {
        $path = $this->scenarioPath();
        $this->app['config']->set('preview.scenario_path', $path);
        $capture = $this->storeHmacCapture('/webhooks/signed', '{"signed":true}');

        $this->app->instance(HttpReplayDispatcher::class, new HttpReplayDispatcher(
            fn (): ReplayResult => new ReplayResult(503, 'receiver unavailable', ['X-Replay' => ['failed']]),
        ));

        $this->writeScenario($path, 'signed-json.php', sprintf(<<<'PHP'
<?php

use Oxhq\Preview\Scenario\Scenario;

return new Scenario(
    name: 'signed-json-flow',
    captures: ['%s'],
);
PHP, $capture->id));

        [$exitCode, $output] = $this->runReplayJson([
            'scenario' => 'signed-json-flow',
            '--resign' => true,
            '--send-to' => 'https://receiver.test',
            '--json' => true,
        ]);

        $payload = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exitCode);
        $this->assertSame('signed-json-flow', $payload['scenario']);
        $this->assertSame('resign', $payload['mode']);
        $this->assertNull($payload['seed']);
        $this->assertFalse($payload['successful']);
        $this->assertSame("Scenario replay failed: dispatch for capture [{$capture->id}] returned HTTP 503.", $payload['failure']);
        $this->assertSame([
            'seed' => 0,
            'captures' => 1,
            'dispatches' => 1,
            'routes' => 0,
        ], $payload['summary']);
        $this->assertSame($capture->id, $payload['captures'][0]['id']);
        $this->assertSame('resign', $payload['captures'][0]['mode']);
        $this->assertSame(503, $payload['captures'][0]['dispatch']['status_code']);
        $this->assertFalse($payload['captures'][0]['dispatch']['successful']);
        $this->assertSame($capture->id, $payload['dispatches'][0]['capture_id']);
        $this->assertSame(503, $payload['dispatches'][0]['status_code']);
        $this->assertSame('receiver unavailable', $payload['dispatches'][0]['body']);
        $this->assertSame(['X-Replay' => ['failed']], $payload['dispatches'][0]['headers']);
        $this->assertFalse($payload['dispatches'][0]['successful']);
        $this->assertSame([], $payload['routes']);
    }

    public function test_preview_scenario_replay_rejects_missing_scenarios_clearly(): void
    {
        $this->app['config']->set('preview.scenario_path', $this->scenarioPath());

        $this->artisan('preview:scenario:replay', [
            'scenario' => 'missing-flow',
            '--exact' => true,
        ])
            ->expectsOutput('Scenario [missing-flow] was not found.')
            ->expectsOutput('Scenario replay failed before a result was available for [missing-flow] using [exact].')
            ->assertExitCode(1);
    }

    public function test_preview_scenario_replay_rejects_missing_captures_clearly(): void
    {
        $path = $this->scenarioPath();
        $this->app['config']->set('preview.scenario_path', $path);

        $this->writeScenario($path, 'missing-capture.php', <<<'PHP'
<?php

use Oxhq\Preview\Scenario\Scenario;

return new Scenario(
    name: 'missing-capture-flow',
    captures: ['missing-capture'],
);
PHP);

        $this->artisan('preview:scenario:replay', [
            'scenario' => 'missing-capture-flow',
            '--exact' => true,
        ])
            ->expectsOutput('Capture [missing-capture] was not found.')
            ->expectsOutput('Scenario replay failed before a result was available for [missing-capture-flow] using [exact].')
            ->assertExitCode(1);
    }

    public function test_preview_scenario_replay_requires_one_explicit_mode(): void
    {
        $this->artisan('preview:scenario:replay', ['scenario' => 'checkout-flow'])
            ->expectsOutput('Choose exactly one scenario replay mode: --exact or --resign.')
            ->assertExitCode(1);

        $this->artisan('preview:scenario:replay', [
            'scenario' => 'checkout-flow',
            '--exact' => true,
            '--resign' => true,
        ])
            ->expectsOutput('Choose exactly one scenario replay mode: --exact or --resign.')
            ->assertExitCode(1);
    }

    public function test_preview_scenario_replay_can_dispatch_each_capture_to_http_target(): void
    {
        $path = $this->scenarioPath();
        $this->app['config']->set('preview.scenario_path', $path);
        $capture = $this->storeGenericCapture('/webhooks/orders');
        $requests = [];

        $this->app->instance(HttpReplayDispatcher::class, new HttpReplayDispatcher(
            function (string $url, string $method, array $headers, string $body, array $payload) use (&$requests): ReplayResult {
                $requests[] = compact('url', 'method', 'headers', 'body', 'payload');

                return new ReplayResult(204, '');
            },
        ));

        $this->writeScenario($path, 'dispatch.php', sprintf(<<<'PHP'
<?php

use Oxhq\Preview\Scenario\Scenario;

return new Scenario(
    name: 'dispatch-flow',
    captures: ['%s'],
);
PHP, $capture->id));

        $this->artisan('preview:scenario:replay', [
            'scenario' => 'dispatch-flow',
            '--exact' => true,
            '--send-to' => 'https://receiver.test',
        ])
            ->expectsOutput('Scenario replay ready for [dispatch-flow] using [exact].')
            ->expectsOutput("Capture: {$capture->id} POST /webhooks/orders")
            ->expectsOutput('Replay HTTP status: 204')
            ->expectsOutput('Replay dispatch: success')
            ->expectsOutput('Summary: seed=0 captures=1 dispatches=1 routes=0')
            ->assertExitCode(0);

        $this->assertSame('https://receiver.test/webhooks/orders', $requests[0]['url']);
        $this->assertSame('POST', $requests[0]['method']);
        $this->assertSame('{"ok":true}', $requests[0]['body']);
    }

    public function test_preview_scenario_replay_reports_failed_dispatch_with_summary(): void
    {
        $path = $this->scenarioPath();
        $this->app['config']->set('preview.scenario_path', $path);
        $capture = $this->storeGenericCapture('/webhooks/orders');

        $this->app->instance(HttpReplayDispatcher::class, new HttpReplayDispatcher(
            fn (): ReplayResult => new ReplayResult(500, 'receiver down'),
        ));

        $this->writeScenario($path, 'dispatch-failure.php', sprintf(<<<'PHP'
<?php

use Oxhq\Preview\Scenario\Scenario;

return new Scenario(
    name: 'dispatch-failure-flow',
    captures: ['%s'],
);
PHP, $capture->id));

        $this->artisan('preview:scenario:replay', [
            'scenario' => 'dispatch-failure-flow',
            '--exact' => true,
            '--send-to' => 'https://receiver.test',
        ])
            ->expectsOutput('Scenario replay ready for [dispatch-failure-flow] using [exact].')
            ->expectsOutput("Capture: {$capture->id} POST /webhooks/orders")
            ->expectsOutput('Replay HTTP status: 500')
            ->expectsOutput('Replay dispatch: failure')
            ->expectsOutput('Summary: seed=0 captures=1 dispatches=1 routes=0')
            ->expectsOutput("Scenario replay failed: dispatch for capture [{$capture->id}] returned HTTP 500.")
            ->assertExitCode(1);
    }

    private function storeGenericCapture(string $path): object
    {
        return $this->app->make(CaptureRepository::class)->store(
            PreviewRequest::make(
                provider: 'generic',
                method: 'POST',
                path: $path,
                query: [],
                headers: ['X-Preview-Event' => 'scenario.command'],
                rawBody: '{"ok":true}',
            ),
            new GenericProvider(),
        );
    }

    private function storeHmacCapture(string $path, string $body): object
    {
        $provider = new GenericHmacProvider('X-Signature', 'test-secret');

        return $this->app->make(CaptureRepository::class)->store(
            PreviewRequest::make(
                provider: 'hmac',
                method: 'POST',
                path: $path,
                query: [],
                headers: [
                    'X-Signature' => hash_hmac('sha256', $body, 'test-secret'),
                ],
                rawBody: $body,
            ),
            $provider,
        );
    }

    private function scenarioPath(): string
    {
        return sys_get_temp_dir().'/preview-tests/scenarios/'.spl_object_id($this);
    }

    private function writeScenario(string $path, string $name, string $contents): string
    {
        if (! is_dir($path)) {
            mkdir($path, 0777, true);
        }

        $file = $path.'/'.$name;
        file_put_contents($file, $contents);

        return $file;
    }

    /**
     * @param array<string, mixed> $parameters
     * @return array{0: int, 1: string}
     */
    private function runReplayJson(array $parameters): array
    {
        $output = new \Symfony\Component\Console\Output\BufferedOutput();
        $exitCode = $this->app->make(Kernel::class)->call('preview:scenario:replay', $parameters, $output);

        return [$exitCode, $output->fetch()];
    }
}

final class CommandRecordingScenarioSeeder extends Seeder
{
    public static int $runs = 0;

    public function run(): void
    {
        self::$runs++;
    }
}
