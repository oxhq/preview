<?php

declare(strict_types=1);

namespace Oxhq\Preview\Tests\Commands;

use Illuminate\Support\Facades\Artisan;
use Oxhq\Preview\Core\ProviderRegistry;
use Oxhq\Preview\Core\Transport\TransportRegistry;
use Oxhq\Preview\Core\Transport\TunnelHandle;
use Oxhq\Preview\Core\Transport\TunnelTransport;
use Oxhq\Preview\Tests\TestCase;

final class PreviewDoctorCommandTest extends TestCase
{
    public function test_it_reports_local_readiness_without_printing_payloads_or_secrets(): void
    {
        [$storagePath, $fixturePath, $scenarioPath] = $this->configurePaths('text');
        $this->makeDirectory($storagePath);
        $this->makeDirectory($fixturePath);
        $this->makeDirectory($scenarioPath);
        $this->writeCaptureMetadata($storagePath, 'capture-one');
        $this->writeCaptureMetadata($storagePath, 'capture-two');
        file_put_contents($storagePath.'/capture-one/body.raw', 'raw-super-secret-payload');
        $this->writeScenarioFile($scenarioPath, 'checkout.php');
        $this->writeScenarioFile($scenarioPath, 'refund.php');

        $exitCode = Artisan::call('preview:doctor');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Preview readiness summary:', $output);
        $this->assertStringContainsString(' - Storage path: '.$storagePath.' (exists: yes)', $output);
        $this->assertStringContainsString(' - Fixture path: '.$fixturePath.' (exists: yes)', $output);
        $this->assertStringContainsString(' - Scenario path: '.$scenarioPath.' (exists: yes)', $output);
        $this->assertStringContainsString(' - Providers: '.count($this->app->make(ProviderRegistry::class)->all()), $output);
        $this->assertStringContainsString(' - Transports: '.count($this->app->make(TransportRegistry::class)->all()), $output);
        $this->assertStringContainsString(' - Captures: 2', $output);
        $this->assertStringContainsString(' - Scenarios: 2', $output);
        $this->assertStringNotContainsString('raw-super-secret-payload', $output);
    }

    public function test_it_outputs_machine_readable_json_summary(): void
    {
        [$storagePath, $fixturePath, $scenarioPath] = $this->configurePaths('json');
        $this->makeDirectory($storagePath);
        $this->makeDirectory($scenarioPath);
        $this->writeCaptureMetadata($storagePath, 'capture-one');
        $this->writeScenarioFile($scenarioPath, 'checkout.php');
        $this->writeScenarioFile($scenarioPath, 'refund.php');

        $exitCode = Artisan::call('preview:doctor', ['--json' => true]);
        $summary = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame([
            'paths' => [
                'storage' => [
                    'path' => $storagePath,
                    'exists' => true,
                ],
                'fixtures' => [
                    'path' => $fixturePath,
                    'exists' => false,
                ],
                'scenarios' => [
                    'path' => $scenarioPath,
                    'exists' => true,
                ],
            ],
            'counts' => [
                'providers' => count($this->app->make(ProviderRegistry::class)->all()),
                'transports' => count($this->app->make(TransportRegistry::class)->all()),
                'captures' => 1,
                'scenarios' => 2,
            ],
        ], $summary);
    }

    public function test_it_does_not_open_transports_or_load_scenario_files(): void
    {
        [$storagePath, $fixturePath, $scenarioPath] = $this->configurePaths('passive');
        $this->makeDirectory($storagePath);
        $this->makeDirectory($fixturePath);
        $this->makeDirectory($scenarioPath);
        file_put_contents($scenarioPath.'/explosive.php', <<<'PHP'
<?php

throw new RuntimeException('Scenario files must not be loaded by preview:doctor.');
PHP);

        $transport = new PreviewDoctorRecordingTunnelTransport();
        $registry = new TransportRegistry();
        $registry->register('custom', $transport);
        $this->app->instance(TransportRegistry::class, $registry);

        $exitCode = Artisan::call('preview:doctor');

        $this->assertSame(0, $exitCode);
        $this->assertSame([], $transport->openedLocalUrls);
        $this->assertStringContainsString(' - Scenarios: 1', Artisan::output());
    }

    /** @return array{string, string, string} */
    private function configurePaths(string $namespace): array
    {
        $root = sys_get_temp_dir().'/preview-tests/doctor/'.$namespace;
        $storagePath = $root.'/captures';
        $fixturePath = $root.'/fixtures';
        $scenarioPath = $root.'/scenarios';

        config()->set('preview.storage_path', $storagePath);
        config()->set('preview.fixture_path', $fixturePath);
        config()->set('preview.scenario_path', $scenarioPath);

        return [$storagePath, $fixturePath, $scenarioPath];
    }

    private function makeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            mkdir($path, 0777, true);
        }
    }

    private function writeCaptureMetadata(string $storagePath, string $id): void
    {
        $directory = $storagePath.'/'.$id;
        $this->makeDirectory($directory);

        file_put_contents($directory.'/metadata.json', json_encode(['id' => $id], JSON_PRETTY_PRINT));
    }

    private function writeScenarioFile(string $scenarioPath, string $name): void
    {
        file_put_contents($scenarioPath.'/'.$name, '<?php return null;'.PHP_EOL);
    }
}

final class PreviewDoctorRecordingTunnelTransport implements TunnelTransport
{
    /** @var list<string> */
    public array $openedLocalUrls = [];

    public function open(string $localUrl): TunnelHandle
    {
        $this->openedLocalUrls[] = $localUrl;

        return new TunnelHandle('https://public.example.test');
    }

    public function close(TunnelHandle $handle): void
    {
    }
}
