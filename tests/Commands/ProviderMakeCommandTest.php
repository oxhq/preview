<?php

declare(strict_types=1);

namespace Oxhq\Preview\Tests\Commands;

use Illuminate\Contracts\Console\Kernel;
use Oxhq\Preview\Commands\ProviderMakeCommand;
use Oxhq\Preview\Tests\TestCase;

final class ProviderMakeCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(Kernel::class)->registerCommand($this->app->make(ProviderMakeCommand::class));
    }

    public function test_it_generates_a_preview_provider_scaffold(): void
    {
        $path = sys_get_temp_dir().'/preview-tests/provider-scaffolds';

        $this->artisan('preview:provider:make', [
            'name' => 'acme-pay',
            '--class' => 'App\\Preview\\Providers\\AcmePayProvider',
            '--path' => $path,
        ])
            ->expectsOutputToContain('Created Preview provider [App\\Preview\\Providers\\AcmePayProvider].')
            ->assertExitCode(0);

        $file = $path.DIRECTORY_SEPARATOR.'AcmePayProvider.php';
        $contents = (string) file_get_contents($file);

        $this->assertFileExists($file);
        $this->assertStringContainsString('namespace App\\Preview\\Providers;', $contents);
        $this->assertStringContainsString('final class AcmePayProvider implements PreviewProvider', $contents);
        $this->assertStringContainsString("return 'acme-pay';", $contents);
        $this->assertStringContainsString("VerificationResult::failed('Not implemented.')", $contents);
    }

    public function test_it_rejects_unsafe_provider_names_and_class_names(): void
    {
        $path = sys_get_temp_dir().'/preview-tests/provider-scaffolds-invalid';

        $this->artisan('preview:provider:make', [
            'name' => '../bad',
            '--path' => $path,
        ])
            ->expectsOutput('Provider name must start with a letter and contain only lowercase letters, numbers, dashes, or underscores.')
            ->assertExitCode(1);

        $this->artisan('preview:provider:make', [
            'name' => 'acme',
            '--class' => '../Bad',
            '--path' => $path,
        ])
            ->expectsOutput('Provider class must be a valid PHP class name.')
            ->assertExitCode(1);
    }

    public function test_it_refuses_to_overwrite_existing_provider_files(): void
    {
        $path = sys_get_temp_dir().'/preview-tests/provider-scaffolds-existing';
        mkdir($path, 0775, true);
        file_put_contents($path.'/AcmeProvider.php', '<?php');

        $this->artisan('preview:provider:make', [
            'name' => 'acme',
            '--class' => 'App\\Preview\\Providers\\AcmeProvider',
            '--path' => $path,
        ])
            ->expectsOutputToContain('already exists')
            ->assertExitCode(1);
    }
}
