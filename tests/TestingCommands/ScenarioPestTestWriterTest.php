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
            routeParameters: [
                'checkout.show' => ['tenant' => 'acme', 'order' => '42'],
                'checkout.success' => ['receipt' => 'abc123'],
            ],
            captures: ['cap_checkout_completed', 'cap_order_created'],
            fakes: ['queue', 'events'],
            routeContext: [
                'checkout.show' => [
                    'session' => ['currency' => 'usd', 'tenant' => 'acme'],
                    'guard' => 'web',
                    'user_id' => '42',
                    'user_model' => 'App\\Models\\User',
                    'readonly_db' => true,
                    'fakes' => ['mail'],
                ],
                'checkout.success' => [
                    'session' => ['receipt_viewed' => 'yes'],
                    'fakes' => ['http'],
                ],
            ],
        ));

        $contents = (string) file_get_contents($path);
        $normalized = str_replace('\\', '/', $path);

        $this->assertStringEndsWith('/Feature/Preview/Scenario/checkout-flowTest.php', $normalized);
        $this->assertStringStartsWith("<?php\n\n", $contents);
        $this->assertStringContainsString('use Oxhq\\Preview\\Scenario\\ScenarioRunner;', $contents);
        $this->assertStringContainsString("it('replays checkout-flow preview scenario'", $contents);
        $this->assertStringContainsString("app(ScenarioRunner::class)->replay('checkout-flow', 'exact')", $contents);
        $this->assertStringNotContainsString('preview:scenario:replay', $contents);
        $this->assertStringContainsString("\$this->assertSame('checkout-flow', \$result->scenario->name);", $contents);
        $this->assertStringContainsString("\$this->assertSame('exact', \$result->mode);", $contents);

        $this->assertStringContainsString(
            'Precondition: run seed [Database\\Seeders\\CheckoutScenarioSeeder] before replaying this scenario.',
            $contents,
        );
        $this->assertStringContainsString("\$this->assertSame('Database\\\\Seeders\\\\CheckoutScenarioSeeder', \$result->seed);", $contents);
        $this->assertStringContainsString('$this->assertCount(2, $result->captures);', $contents);
        $this->assertStringContainsString("\$this->assertSame('cap_checkout_completed', \$result->captures[0]['id'] ?? null);", $contents);
        $this->assertStringContainsString("\$this->assertSame('cap_order_created', \$result->captures[1]['id'] ?? null);", $contents);
        $this->assertStringContainsString('$this->assertCount(2, $result->dispatches);', $contents);
        $this->assertStringContainsString(
            'Scenario fake boundaries requested: queue, events.',
            $contents,
        );
        $this->assertStringContainsString(
            'Route replay expected: checkout.show',
            $contents,
        );
        $this->assertStringContainsString(
            'Route [checkout.show] parameters required by scenario: tenant=acme, order=42.',
            $contents,
        );
        $this->assertStringContainsString(
            'Route [checkout.show] session keys required by scenario: currency, tenant.',
            $contents,
        );
        $this->assertStringNotContainsString('currency=usd', $contents);
        $this->assertStringContainsString(
            'Route [checkout.show] guard context requested: web.',
            $contents,
        );
        $this->assertStringContainsString(
            'Route [checkout.show] user context requested: user id 42 via App\\Models\\User.',
            $contents,
        );
        $this->assertStringContainsString(
            'Route [checkout.show] readonly-db requested.',
            $contents,
        );
        $this->assertStringContainsString(
            'Route [checkout.show] fake boundaries requested: mail.',
            $contents,
        );
        $this->assertStringContainsString(
            'Route replay expected: checkout.success',
            $contents,
        );
        $this->assertStringContainsString(
            'Route [checkout.success] parameters required by scenario: receipt=abc123.',
            $contents,
        );
        $this->assertStringContainsString(
            'Route [checkout.success] session keys required by scenario: receipt_viewed.',
            $contents,
        );
        $this->assertStringNotContainsString('receipt_viewed=yes', $contents);
        $this->assertStringContainsString(
            'Route [checkout.success] fake boundaries requested: http.',
            $contents,
        );
        $this->assertStringContainsString('$this->assertCount(2, $result->routes);', $contents);
        $this->assertStringContainsString("\$this->assertSame('checkout.show', \$result->routes[0]->preview->name);", $contents);
        $this->assertStringContainsString('$this->assertTrue($result->routes[0]->successful());', $contents);
        $this->assertStringContainsString('$this->assertGreaterThanOrEqual(200, $result->routes[0]->response->getStatusCode());', $contents);
        $this->assertStringContainsString('$this->assertLessThan(300, $result->routes[0]->response->getStatusCode());', $contents);
        $this->assertStringContainsString("\$this->assertSame('checkout.success', \$result->routes[1]->preview->name);", $contents);
        $this->assertStringContainsString('$this->assertTrue($result->routes[1]->successful());', $contents);

        $this->assertPhpFileIsLintable($path);
        $this->assertGeneratedPestTestIsStructurallyRunnable($path, [
            'description' => 'replays checkout-flow preview scenario',
            'scenario' => 'checkout-flow',
            'mode' => 'exact',
            'seed' => 'Database\\Seeders\\CheckoutScenarioSeeder',
            'captures' => ['cap_checkout_completed', 'cap_order_created'],
            'routes' => ['checkout.show', 'checkout.success'],
            'dispatches' => 2,
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
        $this->assertStringContainsString('$this->assertNull($result->seed);', $contents);
        $this->assertStringContainsString('$this->assertCount(0, $result->captures);', $contents);
        $this->assertStringContainsString('$this->assertCount(0, $result->routes);', $contents);
        $this->assertStringNotContainsString('$this->assertCount(0, $result->dispatches);', $contents);
        $this->assertPhpFileIsLintable($path);
    }

    public function test_it_generates_lintable_php_when_scenario_values_need_escaping(): void
    {
        $root = sys_get_temp_dir().'/preview-scenario-pest-escaping-'.bin2hex(random_bytes(4));

        $path = (new ScenarioPestTestWriter($root.'/Feature'))->write(new Scenario(
            name: "checkout's\nflow",
            seed: "Database\\Seeders\\CheckoutScenarioSeeder?>",
            routes: ["checkout.success\n?>"],
            routeParameters: ["checkout.success\n?>" => ["tenant\n?>" => "acme\n?>"]],
            captures: ["cap_order's\ncreated"],
            fakes: ["queue\n?>"],
            routeContext: [
                "checkout.success\n?>" => [
                    'session' => ["secret\n?>" => "do-not-print\n?>"],
                    'guard' => "web\n?>",
                    'user_id' => "42\n?>",
                    'user_model' => "App\\Models\\User\n?>",
                    'readonly_db' => true,
                    'fakes' => ["mail\n?>"],
                ],
            ],
        ));

        $contents = (string) file_get_contents($path);

        $this->assertStringContainsString("it('replays checkout\\'s", $contents);
        $this->assertStringContainsString('Database\\Seeders\\CheckoutScenarioSeeder? >', $contents);
        $this->assertStringContainsString('Route replay expected: checkout.success ? >', $contents);
        $this->assertStringContainsString('Scenario fake boundaries requested: queue ? >.', $contents);
        $this->assertStringContainsString('Route [checkout.success ? >] parameters required by scenario: tenant ? >=acme ? >.', $contents);
        $this->assertStringContainsString('Route [checkout.success ? >] session keys required by scenario: secret ? >.', $contents);
        $this->assertStringNotContainsString('do-not-print', $contents);
        $this->assertStringContainsString('Route [checkout.success ? >] guard context requested: web ? >.', $contents);
        $this->assertStringContainsString('Route [checkout.success ? >] user context requested: user id 42 ? > via App\\Models\\User ? >.', $contents);
        $this->assertStringContainsString('Route [checkout.success ? >] readonly-db requested.', $contents);
        $this->assertStringContainsString('Route [checkout.success ? >] fake boundaries requested: mail ? >.', $contents);
        $this->assertPhpFileIsLintable($path);
    }

    /**
     * @param array{description: string, scenario: string, mode: string, seed: string|null, captures: list<string>, routes: list<string>, dispatches: int|null} $expected
     */
    private function assertGeneratedPestTestIsStructurallyRunnable(string $path, array $expected): void
    {
        $harness = sys_get_temp_dir().'/preview-scenario-pest-harness-'.bin2hex(random_bytes(4)).'.php';
        $expectedPath = sys_get_temp_dir().'/preview-scenario-pest-harness-'.bin2hex(random_bytes(4)).'.json';
        file_put_contents($expectedPath, json_encode($expected, JSON_THROW_ON_ERROR));
        file_put_contents($harness, <<<'PHP'
<?php

declare(strict_types=1);

require getcwd().'/vendor/autoload.php';

use Oxhq\Preview\Route\RoutePreview;
use Oxhq\Preview\Scenario\Scenario;
use Oxhq\Preview\Scenario\ScenarioReplayResult;
use Oxhq\Preview\Scenario\ScenarioRouteResult;
use Symfony\Component\HttpFoundation\Response;

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

    /** @var array{description: string, scenario: string, mode: string, seed: string|null, captures: list<string>, routes: list<string>, dispatches: int|null}|null */
    public static ?array $expected = null;

    /** @var list<array{0: string, 1: string, 2: string|null}> */
    public static array $replayCalls = [];
}

function it(string $description, Closure $test): void
{
    PreviewScenarioGeneratedPestHarness::$description = $description;
    PreviewScenarioGeneratedPestHarness::$test = $test;
}

function app(?string $abstract = null): object
{
    if ($abstract !== \Oxhq\Preview\Scenario\ScenarioRunner::class) {
        fwrite(STDERR, 'Generated Pest test resolved the wrong service.');
        exit(13);
    }

    return new class {
        public function replay(string $scenarioName, string $mode, ?string $sendTo = null): ScenarioReplayResult
        {
            PreviewScenarioGeneratedPestHarness::$replayCalls[] = [$scenarioName, $mode, $sendTo];
            $expected = PreviewScenarioGeneratedPestHarness::$expected;

            if (! is_array($expected)) {
                fwrite(STDERR, 'Expected assertion payload was not loaded.');
                exit(14);
            }

            $captures = array_map(
                static fn (string $capture): array => ['id' => $capture],
                $expected['captures'],
            );
            $routes = array_map(
                static fn (string $route): ScenarioRouteResult => new ScenarioRouteResult(
                    new RoutePreview(
                        name: $route,
                        uri: '/'.$route,
                        action: 'GeneratedScenarioHarnessController',
                        domain: null,
                        methods: ['GET'],
                        middleware: [],
                        executionMethod: 'GET',
                        url: 'http://localhost/'.$route,
                        expiresAt: new \DateTimeImmutable('+5 minutes'),
                    ),
                    new Response('ok', 200),
                ),
                $expected['routes'],
            );
            $dispatches = array_fill(0, $expected['dispatches'] ?? 0, null);

            return new ScenarioReplayResult(
                scenario: new Scenario(
                    name: $expected['scenario'],
                    seed: $expected['seed'],
                    routes: $expected['routes'],
                    captures: $expected['captures'],
                ),
                mode: $mode,
                seed: $expected['seed'],
                captures: $captures,
                dispatches: $dispatches,
                routes: $routes,
            );
        }
    };
}

PreviewScenarioGeneratedPestHarness::$expected = $expected;

require $generatedPath;

if (PreviewScenarioGeneratedPestHarness::$test === null) {
    fwrite(STDERR, 'Generated Pest test did not register an it() closure.');
    exit(5);
}

$testCase = new class('runTest') extends \PHPUnit\Framework\TestCase {
    public function runTest(): void
    {
    }
};

$test = PreviewScenarioGeneratedPestHarness::$test->bindTo($testCase, $testCase);
$test();

if (PreviewScenarioGeneratedPestHarness::$description !== $expected['description']) {
    fwrite(STDERR, 'Unexpected Pest test description.');
    exit(6);
}

if (count(PreviewScenarioGeneratedPestHarness::$replayCalls) !== 1) {
    fwrite(STDERR, 'Generated Pest test should replay exactly once.');
    exit(7);
}

[$scenarioName, $mode, $sendTo] = PreviewScenarioGeneratedPestHarness::$replayCalls[0];

if ($scenarioName !== $expected['scenario']) {
    fwrite(STDERR, 'Generated Pest test replayed the wrong scenario.');
    exit(9);
}

if ($mode !== $expected['mode']) {
    fwrite(STDERR, 'Generated Pest test should replay in exact mode.');
    exit(10);
}

if ($sendTo !== null) {
    fwrite(STDERR, 'Generated Pest test should not dispatch scenario replays.');
    exit(11);
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
