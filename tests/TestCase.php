<?php

declare(strict_types=1);

namespace Oxhq\Preview\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Oxhq\Preview\PreviewServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->removeDirectory(sys_get_temp_dir().'/preview-tests');
    }

    protected function getPackageProviders($app): array
    {
        return [
            PreviewServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('preview.storage_path', sys_get_temp_dir().'/preview-tests/captures');
        $app['config']->set('preview.fixture_path', sys_get_temp_dir().'/preview-tests/fixtures');
        $app['config']->set('preview.test_path', sys_get_temp_dir().'/preview-tests/tests');
        $app['config']->set('preview.hmac.secret', 'test-secret');
        $app['config']->set('preview.github.webhook_secret', 'github-test-secret');
        $app['config']->set('preview.shopify.client_secret', 'shopify-test-secret');
        $app['config']->set('preview.stripe.endpoint_secret', 'whsec_test');
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($path);
    }
}
