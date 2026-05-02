<?php

declare(strict_types=1);

namespace Oxhq\Preview\Tests\Acceptance {
    use Closure;
    use Illuminate\Http\Request;
    use Illuminate\Support\Facades\Route;
    use Oxhq\Preview\Capture\CaptureRepository;
    use Oxhq\Preview\Capture\ReplayService;
    use Oxhq\Preview\Testing\FixtureWriter;
    use Oxhq\Preview\Testing\PestTestWriter;
    use Oxhq\Preview\Testing\PreviewFixture;
    use Oxhq\Preview\Tests\TestCase;

    final class GeneratedHmacPreviewTestAcceptanceTest extends TestCase
    {
        public function test_generated_hmac_fixture_and_pest_test_can_resign_and_execute_against_testbench_route(): void
        {
            GeneratedPestTestRegistry::reset();

            Route::post('/webhook/hmac', function (Request $request) {
                $expected = hash_hmac('sha256', $request->getContent(), 'test-secret');

                if (! hash_equals($expected, (string) $request->header('X-Custom-Signature'))) {
                    return response()->json(['ok' => false], 401);
                }

                return response()->json(['ok' => true]);
            });

            $body = '{"event":"hmac.created","id":123}';
            $originalSignature = hash_hmac('sha256', $body, 'test-secret');

            $captureResponse = $this->call(
                'POST',
                '/__preview/capture/hmac?signature_header=X-Custom-Signature',
                [],
                [],
                [],
                [
                    'CONTENT_TYPE' => 'application/json',
                    'HTTP_X_PREVIEW_ORIGINAL_PATH' => '/webhook/hmac',
                    'HTTP_X_PREVIEW_EVENT' => 'hmac.created',
                    'HTTP_X_CUSTOM_SIGNATURE' => $originalSignature,
                ],
                $body,
            );

            $captureResponse->assertOk();
            $captureResponse->assertJson([
                'provider' => 'hmac',
                'event_type' => 'hmac.created',
                'verified' => true,
                'verification_message' => null,
            ]);

            $record = app(CaptureRepository::class)->find((string) $captureResponse->json('id'));

            $this->assertTrue($record->verified);
            $this->assertSame('/webhook/hmac', $record->path);
            $this->assertSame([
                'signature_header' => 'X-Custom-Signature',
                'algorithm' => 'sha256',
            ], $record->metadata['fixture_context']);

            $replay = app(ReplayService::class)->resign($record);
            $this->assertSame('resign', $replay['mode']);
            $this->assertSame('/webhook/hmac', $replay['path']);
            $this->assertSame($body, $replay['raw_body']);
            $this->assertSame(
                hash_hmac('sha256', $body, 'test-secret'),
                $replay['headers']['X-Custom-Signature'],
            );

            $testPath = app(PestTestWriter::class)->write($record, providerCanSign: true);
            $fixturePath = app(FixtureWriter::class)->fixturePath($record);

            $this->assertPhpFileIsLintable($fixturePath);
            $this->assertPhpFileIsLintable($testPath);

            $fixture = PreviewFixture::load($fixturePath);
            $this->assertInstanceOf(PreviewFixture::class, $fixture);
            $this->assertSame('hmac', $fixture->providerName());
            $this->assertSame('resign', $fixture->signingMode());
            $this->assertSame([
                'signature_header' => 'X-Custom-Signature',
                'algorithm' => 'sha256',
            ], $fixture->fixtureContext());

            $generatedTestPhp = (string) file_get_contents($testPath);
            $this->assertStringContainsString('$fixture->freshSignedHeaders()', $generatedTestPhp);
            $this->assertStringContainsString('handles hmac hmac.created', $generatedTestPhp);

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
    }
}

namespace {
    use Oxhq\Preview\Tests\Acceptance\GeneratedPestTestRegistry;

    if (! function_exists('it')) {
        function it(string $description, \Closure $test): void
        {
            GeneratedHmacPestTestRegistry::register($description, $test);
        }
    }
}
