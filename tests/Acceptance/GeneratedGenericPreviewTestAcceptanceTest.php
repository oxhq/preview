<?php

declare(strict_types=1);

namespace Oxhq\Preview\Tests\Acceptance {
    use Closure;
    use DateTimeImmutable;
    use Illuminate\Support\Facades\Route;
    use Oxhq\Preview\Capture\CaptureRecord;
    use Oxhq\Preview\Testing\PestTestWriter;
    use Oxhq\Preview\Testing\PreviewFixture;
    use Oxhq\Preview\Tests\TestCase;

    final class GeneratedGenericPreviewTestAcceptanceTest extends TestCase
    {
        public function test_generated_generic_fixture_and_pest_test_can_be_loaded_and_executed(): void
        {
            GeneratedPestTestRegistry::reset();

            Route::post('/webhook/generic', fn () => response()->json(['ok' => true]));

            $bodyPath = sys_get_temp_dir().'/preview-tests/generic-body.json';
            $this->ensureDirectory(dirname($bodyPath));
            file_put_contents($bodyPath, '{"event":"generic.created"}');

            $record = new CaptureRecord(
                id: 'cap_generic_1',
                provider: 'generic',
                eventType: 'generic.created',
                method: 'POST',
                path: '/webhook/generic',
                query: [],
                headers: ['X-Preview-Test' => 'acceptance'],
                rawBodyPath: $bodyPath,
                capturedAt: new DateTimeImmutable(),
                verified: true,
                metadata: ['fixture_name' => 'generic-created'],
            );

            $testPath = app(PestTestWriter::class)->write($record, providerCanSign: false);
            $fixturePath = config('preview.fixture_path').'/generic/generic-created/fixture.php';

            $this->assertPhpFileIsLintable($fixturePath);
            $this->assertPhpFileIsLintable($testPath);
            $this->assertInstanceOf(PreviewFixture::class, PreviewFixture::load($fixturePath));

            require $testPath;

            $generatedTest = GeneratedPestTestRegistry::test()->bindTo($this, self::class);
            $this->assertInstanceOf(Closure::class, $generatedTest);

            $generatedTest();
        }

        private function assertPhpFileIsLintable(string $path): void
        {
            $output = [];
            $exitCode = 1;

            exec(PHP_BINARY.' -l '.escapeshellarg($path), $output, $exitCode);

            $this->assertSame(0, $exitCode, implode(PHP_EOL, $output));
        }

        private function ensureDirectory(string $path): void
        {
            if (! is_dir($path)) {
                mkdir($path, 0775, true);
            }
        }
    }
}

namespace {
    use Oxhq\Preview\Tests\Acceptance\GeneratedPestTestRegistry;

    if (! function_exists('it')) {
        function it(string $description, \Closure $test): void
        {
            GeneratedPestTestRegistry::register($description, $test);
        }
    }
}
