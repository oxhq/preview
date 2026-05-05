<?php

declare(strict_types=1);

namespace Oxhq\Preview\Tests\Commands;

use Illuminate\Database\Seeder;
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

    public function test_preview_scenario_replay_runs_seed_replays_exact_captures_and_marks_routes_as_metadata_only(): void
    {
        $path = $this->scenarioPath();
        $this->app['config']->set('preview.scenario_path', $path);
        $capture = $this->storeGenericCapture('/webhooks/orders');

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
            ->expectsOutput('Routes: checkout.show (metadata only; route composition is not implemented in this command)')
            ->expectsOutput("Capture: {$capture->id} POST /webhooks/orders")
            ->assertExitCode(0);

        $this->assertSame(1, CommandRecordingScenarioSeeder::$runs);
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
            ->assertExitCode(0);
    }

    public function test_preview_scenario_replay_rejects_missing_scenarios_clearly(): void
    {
        $this->app['config']->set('preview.scenario_path', $this->scenarioPath());

        $this->artisan('preview:scenario:replay', [
            'scenario' => 'missing-flow',
            '--exact' => true,
        ])
            ->expectsOutput('Scenario [missing-flow] was not found.')
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
            ->assertExitCode(0);

        $this->assertSame('https://receiver.test/webhooks/orders', $requests[0]['url']);
        $this->assertSame('POST', $requests[0]['method']);
        $this->assertSame('{"ok":true}', $requests[0]['body']);
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
}

final class CommandRecordingScenarioSeeder extends Seeder
{
    public static int $runs = 0;

    public function run(): void
    {
        self::$runs++;
    }
}
