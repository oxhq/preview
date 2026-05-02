<?php

declare(strict_types=1);

namespace Oxhq\Preview\Tests\Acceptance {
    use Closure;
    use Illuminate\Http\Request;
    use Illuminate\Support\Facades\Route;
    use Oxhq\Preview\Capture\CaptureRepository;
    use Oxhq\Preview\Capture\PreviewRequest;
    use Oxhq\Preview\Core\ProviderRegistry;
    use Oxhq\Preview\Testing\PestTestWriter;
    use Oxhq\Preview\Testing\PreviewFixture;
    use Oxhq\Preview\Tests\TestCase;

    final class GeneratedStripePreviewTestAcceptanceTest extends TestCase
    {
        public function test_generated_stripe_fixture_and_pest_test_resign_and_execute_against_testbench_route(): void
        {
            GeneratedPestTestRegistry::reset();

            $rawBody = '{"id":"evt_123","type":"checkout.session.completed","data":{"object":{"id":"cs_test_123"}}}';
            $stripe = app(ProviderRegistry::class)->get('stripe');

            Route::post('/webhook/stripe', function (Request $request) use ($rawBody, $stripe) {
                $previewRequest = PreviewRequest::make(
                    provider: 'stripe',
                    method: $request->method(),
                    path: '/webhook/stripe',
                    headers: $request->headers->all(),
                    rawBody: $request->getContent(),
                );

                abort_unless($request->getContent() === $rawBody, 422, 'Raw body changed.');
                abort_unless($stripe->verify($previewRequest)->verified, 400, 'Stripe signature did not verify.');

                return response()->json(['handled' => true]);
            });

            $captureHeaders = $stripe->sign($rawBody, [
                'Content-Type' => 'application/json',
                'X-Preview-Original-Path' => '/webhook/stripe',
            ]);

            $captureResponse = $this->call(
                'POST',
                '/__preview/capture/stripe',
                [],
                [],
                [],
                $this->serverHeaders($captureHeaders),
                $rawBody,
            );

            $captureResponse
                ->assertOk()
                ->assertJson([
                    'provider' => 'stripe',
                    'event_type' => 'checkout.session.completed',
                    'verified' => true,
                    'verification_message' => null,
                ]);

            $record = app(CaptureRepository::class)->find((string) $captureResponse->json('id'));

            $this->assertSame('stripe', $record->provider);
            $this->assertSame('/webhook/stripe', $record->path);
            $this->assertSame($rawBody, $record->rawBody());
            $this->assertTrue($record->verified);

            $testPath = app(PestTestWriter::class)->write($record, providerCanSign: $stripe->canSign());
            $fixturePath = config('preview.fixture_path').'/stripe/stripe-checkout-session-completed/fixture.php';

            $this->assertPhpFileIsLintable($fixturePath);
            $this->assertPhpFileIsLintable($testPath);
            $this->assertStringContainsString('$fixture->freshSignedHeaders()', (string) file_get_contents($testPath));

            $fixture = PreviewFixture::load($fixturePath);
            $freshSignedHeaders = $fixture->freshSignedHeaders();

            $this->assertSame('resign', $fixture->signingMode());
            $this->assertArrayHasKey('Stripe-Signature', $freshSignedHeaders);
            $this->assertTrue($stripe->verify(PreviewRequest::make(
                provider: 'stripe',
                method: $fixture->requestMethod(),
                path: $fixture->endpointPath(),
                headers: ['Stripe-Signature' => $freshSignedHeaders['Stripe-Signature']],
                rawBody: $fixture->rawBody(),
            ))->verified);

            require $testPath;

            $generatedTest = GeneratedPestTestRegistry::test()->bindTo($this, self::class);
            $this->assertInstanceOf(Closure::class, $generatedTest);

            $generatedTest();
        }

        /**
         * @param array<string, mixed> $headers
         * @return array<string, string>
         */
        private function serverHeaders(array $headers): array
        {
            $server = [];

            foreach ($headers as $name => $value) {
                $key = strcasecmp((string) $name, 'Content-Type') === 0
                    ? 'CONTENT_TYPE'
                    : 'HTTP_'.strtoupper(str_replace('-', '_', (string) $name));

                $server[$key] = is_array($value) ? implode(', ', array_map('strval', $value)) : (string) $value;
            }

            return $server;
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
            GeneratedPestTestRegistry::register($description, $test);
        }
    }
}
