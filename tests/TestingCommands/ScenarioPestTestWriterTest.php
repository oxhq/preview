<?php

declare(strict_types=1);

namespace Oxhq\Preview\Tests\TestingCommands;

use Oxhq\Preview\Scenario\Scenario;
use Oxhq\Preview\Testing\ScenarioPestTestWriter;
use PHPUnit\Framework\TestCase;

final class ScenarioPestTestWriterTest extends TestCase
{
    public function test_it_generates_a_pest_compatible_scenario_replay_test_with_seed_capture_and_route_metadata(): void
    {
        $root = sys_get_temp_dir().'/preview-scenario-pest-'.bin2hex(random_bytes(4));

        $writer = new ScenarioPestTestWriter($root.'/Feature');
        $path = $writer->write(new Scenario(
            name: 'checkout-flow',
            seed: 'Database\\Seeders\\CheckoutScenarioSeeder',
            routes: ['checkout.show', 'checkout.success'],
            captures: ['cap_checkout_completed', 'cap_order_created'],
        ));

        $contents = (string) file_get_contents($path);
        $normalized = str_replace('\\', '/', $path);

        $this->assertStringEndsWith('/Feature/Preview/Scenario/checkout-flowTest.php', $normalized);
        $this->assertStringStartsWith("<?php\n\n", $contents);
        $this->assertStringContainsString("it('replays checkout-flow preview scenario'", $contents);
        $this->assertStringContainsString('preview:scenario:replay', $contents);
        $this->assertStringContainsString("'scenario' => 'checkout-flow'", $contents);
        $this->assertStringContainsString("'--exact' => true", $contents);

        $this->assertStringContainsString(
            'Precondition: run seed [Database\\Seeders\\CheckoutScenarioSeeder] before replaying this scenario.',
            $contents,
        );
        $this->assertStringContainsString("->expectsOutputToContain('Seed: Database\\\\Seeders\\\\CheckoutScenarioSeeder')", $contents);
        $this->assertStringContainsString("->expectsOutputToContain('Capture: cap_checkout_completed')", $contents);
        $this->assertStringContainsString("->expectsOutputToContain('Capture: cap_order_created')", $contents);
        $this->assertStringContainsString(
            'Route replay expected: checkout.show',
            $contents,
        );
        $this->assertStringContainsString(
            'Route replay expected: checkout.success',
            $contents,
        );
        $this->assertStringContainsString("->expectsOutputToContain('Route: checkout.show HTTP ')", $contents);
        $this->assertStringContainsString("->expectsOutputToContain('Route: checkout.success HTTP ')", $contents);

        $this->assertPhpFileIsLintable($path);
        $this->assertGeneratedPestTestIsStructurallyRunnable($path, [
            'description' => 'replays checkout-flow preview scenario',
            'scenario' => 'checkout-flow',
            'expected_output_contains' => [
                'Seed: Database\\Seeders\\CheckoutScenarioSeeder',
                'Capture: cap_checkout_completed',
                'Capture: cap_order_created',
                'Route: checkout.show HTTP ',
                'Route: checkout.success HTTP ',
            ],
        ]);
    }

    public function test_it_generates_clear_placeholders_when_a_scenario_has_no_captures_or_routes(): void
    {
        $root = sys_get_temp_dir().'/preview-scenario-pest-empty-'.bin2hex(random_bytes(4));

        $path = (new ScenarioPestTestWriter($root.'/Feature'))->write(new Scenario(name: 'empty-flow'));

        $contents = (string) file_get_contents($path);

        $this->assertStringContainsString('Precondition: no scenario seed configured.', $contents);
        $this->assertStringContainsString('Captures: none listed for this scenario.', $contents);
        $this->assertStringContainsString('Routes: none listed for this scenario.', $contents);
        $this->assertStringContainsString("->expectsOutputToContain('Captures: none')", $contents);
        $this->assertStringContainsString("->expectsOutputToContain('Routes: none')", $contents);
        $this->assertPhpFileIsLintable($path);
    }

    public function test_it_generates_lintable_php_when_scenario_values_need_escaping(): void
    {
        $root = sys_get_temp_dir().'/preview-scenario-pest-escaping-'.bin2hex(random_bytes(4));

        $path = (new ScenarioPestTestWriter($root.'/Feature'))->write(new Scenario(
            name: "checkout's\nflow",
            seed: "Database\\Seeders\\CheckoutScenarioSeeder?>",
            routes: ["checkout.success\n?>"],
            captures: ["cap_order's\ncreated"],
        ));

        $contents = (string) file_get_contents($path);

        $this->assertStringContainsString("it('replays checkout\\'s", $contents);
        $this->assertStringContainsString('Database\\Seeders\\CheckoutScenarioSeeder? >', $contents);
        $this->assertStringContainsString('Route replay expected: checkout.success ? >', $contents);
        $this->assertPhpFileIsLintable($path);
    }

    /**
     * @param array{description: string, scenario: string, expected_output_contains: list<string>} $expected
     */
    private function assertGeneratedPestTestIsStructurallyRunnable(string $path, array $expected): void
    {
        $harness = sys_get_temp_dir().'/preview-scenario-pest-harness-'.bin2hex(random_bytes(4)).'.php';
        $expectedPath = sys_get_temp_dir().'/preview-scenario-pest-harness-'.bin2hex(random_bytes(4)).'.json';
        file_put_contents($expectedPath, json_encode($expected, JSON_THROW_ON_ERROR));
        file_put_contents($harness, <<<'PHP'
<?php

declare(strict_types=1);

$generatedPath = $argv[1] ?? null;
$expectedPath = $argv[2] ?? null;

if (! is_string($generatedPath) || $generatedPath === '' || ! is_file($generatedPath)) {
    fwrite(STDERR, 'Missing generated test path.');
    exit(2);
}

if (! is_string($expectedPath) || $expectedPath === '' || ! is_file($expectedPath)) {
    fwrite(STDERR, 'Missing expected assertion payload.');
    exit(3);
}

$expected = json_decode((string) file_get_contents($expectedPath), true);

if (! is_array($expected)) {
    fwrite(STDERR, 'Invalid expected assertion payload.');
    exit(4);
}

final class PreviewScenarioGeneratedPestHarness
{
    public static ?string $description = null;

    public static ?Closure $test = null;
}

function it(string $description, Closure $test): void
{
    PreviewScenarioGeneratedPestHarness::$description = $description;
    PreviewScenarioGeneratedPestHarness::$test = $test;
}

require $generatedPath;

if (PreviewScenarioGeneratedPestHarness::$test === null) {
    fwrite(STDERR, 'Generated Pest test did not register an it() closure.');
    exit(5);
}

$testCase = new class {
    /** @var list<array{0: string, 1: array<string, mixed>}> */
    public array $artisanCalls = [];

    public ?object $artisanResult = null;

    /**
     * @param array<string, mixed> $arguments
     */
    public function artisan(string $command, array $arguments): object
    {
        $this->artisanCalls[] = [$command, $arguments];

        return $this->artisanResult = new class {
            /** @var list<string> */
            public array $expectedOutputContains = [];

            public ?int $exitCode = null;

            public function expectsOutputToContain(string $output): self
            {
                $this->expectedOutputContains[] = $output;

                return $this;
            }

            public function assertExitCode(int $exitCode): self
            {
                $this->exitCode = $exitCode;

                return $this;
            }
        };
    }
};

$test = PreviewScenarioGeneratedPestHarness::$test->bindTo($testCase, $testCase);
$test();

if (PreviewScenarioGeneratedPestHarness::$description !== $expected['description']) {
    fwrite(STDERR, 'Unexpected Pest test description.');
    exit(6);
}

if (count($testCase->artisanCalls) !== 1) {
    fwrite(STDERR, 'Generated Pest test should call artisan exactly once.');
    exit(7);
}

[$command, $arguments] = $testCase->artisanCalls[0];

if ($command !== 'preview:scenario:replay') {
    fwrite(STDERR, 'Generated Pest test called the wrong artisan command.');
    exit(8);
}

if (($arguments['scenario'] ?? null) !== $expected['scenario']) {
    fwrite(STDERR, 'Generated Pest test passed the wrong scenario argument.');
    exit(9);
}

if (($arguments['--exact'] ?? null) !== true) {
    fwrite(STDERR, 'Generated Pest test should replay with --exact.');
    exit(10);
}

if ($testCase->artisanResult === null || $testCase->artisanResult->exitCode !== 0) {
    fwrite(STDERR, 'Generated Pest test should assert exit code 0.');
    exit(11);
}

$missing = array_values(array_diff(
    $expected['expected_output_contains'],
    $testCase->artisanResult->expectedOutputContains,
));

if ($missing !== []) {
    fwrite(STDERR, 'Missing generated output expectations: '.implode(', ', $missing));
    exit(12);
}

echo 'Generated scenario Pest test is structurally runnable.';
PHP);

        $output = [];
        $exitCode = 1;
        exec(
            PHP_BINARY.' '.escapeshellarg($harness).' '.escapeshellarg($path).' '.escapeshellarg($expectedPath).' 2>&1',
            $output,
            $exitCode,
        );

        $this->assertSame(0, $exitCode, implode(PHP_EOL, $output));
    }

    private function assertPhpFileIsLintable(string $path): void
    {
        $output = [];
        $exitCode = 1;

        exec(PHP_BINARY.' -l '.escapeshellarg($path), $output, $exitCode);

        $this->assertSame(0, $exitCode, implode(PHP_EOL, $output));
    }
}
