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
        $this->assertStringContainsString("->expectsOutputToContain('Capture: cap_checkout_completed')", $contents);
        $this->assertStringContainsString("->expectsOutputToContain('Capture: cap_order_created')", $contents);
        $this->assertStringContainsString(
            'TODO route scenario execution: checkout.show (metadata only until route scenario test execution is implemented)',
            $contents,
        );
        $this->assertStringContainsString(
            'TODO route scenario execution: checkout.success (metadata only until route scenario test execution is implemented)',
            $contents,
        );
    }

    public function test_it_generates_clear_placeholders_when_a_scenario_has_no_captures_or_routes(): void
    {
        $root = sys_get_temp_dir().'/preview-scenario-pest-empty-'.bin2hex(random_bytes(4));

        $path = (new ScenarioPestTestWriter($root.'/Feature'))->write(new Scenario(name: 'empty-flow'));

        $contents = (string) file_get_contents($path);

        $this->assertStringContainsString('Precondition: no scenario seed configured.', $contents);
        $this->assertStringContainsString('Captures: none listed for this scenario.', $contents);
        $this->assertStringContainsString('Routes: none listed for this scenario.', $contents);
    }
}
