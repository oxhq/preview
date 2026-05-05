<?php

declare(strict_types=1);

namespace Oxhq\Preview\Tests\Scenario;

use Illuminate\Database\Seeder;
use Oxhq\Preview\Capture\CaptureRepository;
use Oxhq\Preview\Capture\PreviewRequest;
use Oxhq\Preview\Providers\GenericHmacProvider;
use Oxhq\Preview\Providers\GenericProvider;
use Oxhq\Preview\Scenario\Scenario;
use Oxhq\Preview\Scenario\ScenarioRunner;
use Oxhq\Preview\Tests\TestCase;
use RuntimeException;

final class ScenarioRunnerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        RecordingScenarioSeeder::$runs = 0;
    }

    public function test_it_runs_the_optional_seed_class_through_laravels_seeder_path_before_replaying_exact_captures(): void
    {
        $path = $this->scenarioPath();
        $this->app['config']->set('preview.scenario_path', $path);
        $capture = $this->storeGenericCapture('/webhooks/orders', ['Authorization' => 'Bearer exact-secret']);

        $this->writeScenario($path, 'checkout.php', sprintf(<<<'PHP'
<?php

use Oxhq\Preview\Scenario\Scenario;
use Oxhq\Preview\Tests\Scenario\RecordingScenarioSeeder;

return new Scenario(
    name: 'checkout-flow',
    seed: RecordingScenarioSeeder::class,
    captures: ['%s'],
);
PHP, $capture->id));

        $result = $this->app->make(ScenarioRunner::class)->replay('checkout-flow', 'exact');

        $this->assertSame(1, RecordingScenarioSeeder::$runs);
        $this->assertSame('checkout-flow', $result->scenario->name);
        $this->assertSame('exact', $result->mode);
        $this->assertSame(RecordingScenarioSeeder::class, $result->seed);
        $this->assertCount(1, $result->captures);
        $this->assertSame($capture->id, $result->captures[0]['id']);
        $this->assertSame('Bearer exact-secret', $result->captures[0]['headers']['Authorization']);
    }

    public function test_it_replays_listed_captures_in_resign_mode(): void
    {
        $path = $this->scenarioPath();
        $this->app['config']->set('preview.scenario_path', $path);
        $capture = $this->storeHmacCapture('/webhooks/signed', '{"ok":true}');

        $this->writeScenario($path, 'signed.php', sprintf(<<<'PHP'
<?php

use Oxhq\Preview\Scenario\Scenario;

return new Scenario(
    name: 'signed-flow',
    captures: ['%s'],
);
PHP, $capture->id));

        $result = $this->app->make(ScenarioRunner::class)->replay('signed-flow', 'resign');

        $this->assertSame('resign', $result->mode);
        $this->assertCount(1, $result->captures);
        $this->assertSame($capture->id, $result->captures[0]['id']);
        $this->assertSame(hash_hmac('sha256', '{"ok":true}', 'test-secret'), $result->captures[0]['headers']['X-Signature']);
    }

    public function test_it_rejects_missing_scenarios_clearly(): void
    {
        $this->app['config']->set('preview.scenario_path', $this->scenarioPath());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Scenario [missing-flow] was not found.');

        $this->app->make(ScenarioRunner::class)->replay('missing-flow', 'exact');
    }

    public function test_it_rejects_missing_captures_clearly(): void
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

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Capture [missing-capture] was not found.');

        $this->app->make(ScenarioRunner::class)->replay('missing-capture-flow', 'exact');
    }

    public function test_it_rejects_failing_seeders_clearly(): void
    {
        $path = $this->scenarioPath();
        $this->app['config']->set('preview.scenario_path', $path);

        $this->writeScenario($path, 'failing-seed.php', <<<'PHP'
<?php

use Oxhq\Preview\Scenario\Scenario;
use Oxhq\Preview\Tests\Scenario\FailingScenarioSeeder;

return new Scenario(
    name: 'failing-seed-flow',
    seed: FailingScenarioSeeder::class,
);
PHP);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Scenario seed ['.FailingScenarioSeeder::class.'] failed: seed exploded');

        $this->app->make(ScenarioRunner::class)->replay('failing-seed-flow', 'exact');
    }

    private function storeGenericCapture(string $path, array $headers = []): object
    {
        return $this->app->make(CaptureRepository::class)->store(
            PreviewRequest::make(
                provider: 'generic',
                method: 'POST',
                path: $path,
                query: [],
                headers: $headers,
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

final class RecordingScenarioSeeder extends Seeder
{
    public static int $runs = 0;

    public function run(): void
    {
        self::$runs++;
    }
}

final class FailingScenarioSeeder extends Seeder
{
    public function run(): void
    {
        throw new RuntimeException('seed exploded');
    }
}
