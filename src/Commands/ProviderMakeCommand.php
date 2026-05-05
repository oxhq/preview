<?php

declare(strict_types=1);

namespace Oxhq\Preview\Commands;

use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

final class ProviderMakeCommand extends Command
{
    protected $signature = 'preview:provider:make
        {name : Provider name, for example acme}
        {--class= : Fully-qualified provider class name}
        {--path= : Directory where the provider file should be written}';

    protected $description = 'Generate a Preview provider scaffold for a Laravel application.';

    public function handle(): int
    {
        try {
            $name = $this->providerName();
            [$namespace, $class] = $this->classParts($name);
            $root = $this->targetRoot();
            $path = $root.DIRECTORY_SEPARATOR.$class.'.php';

            $this->ensureDirectory($root);

            if (is_file($path)) {
                throw new RuntimeException("Provider file [{$path}] already exists.");
            }

            if (file_put_contents($path, $this->contents($namespace, $class, $name)) === false) {
                throw new RuntimeException("Provider file [{$path}] could not be written.");
            }
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Created Preview provider [{$namespace}\\{$class}].");
        $this->line($path);

        return self::SUCCESS;
    }

    private function providerName(): string
    {
        $name = strtolower(trim((string) $this->argument('name')));

        if (! preg_match('/^[a-z][a-z0-9_-]*$/', $name)) {
            throw new RuntimeException('Provider name must start with a letter and contain only lowercase letters, numbers, dashes, or underscores.');
        }

        return $name;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function classParts(string $name): array
    {
        $class = $this->option('class');
        $class = is_string($class) && trim($class) !== ''
            ? trim($class, '\\ ')
            : 'App\\Preview\\Providers\\'.$this->studly($name).'Provider';

        if (! preg_match('/^(?:[A-Za-z_][A-Za-z0-9_]*\\\\)*[A-Za-z_][A-Za-z0-9_]*$/', $class)) {
            throw new RuntimeException('Provider class must be a valid PHP class name.');
        }

        $parts = explode('\\', $class);
        $short = array_pop($parts);
        $namespace = implode('\\', $parts);

        if ($namespace === '' || $short === null || $short === '') {
            throw new RuntimeException('Provider class must include a namespace.');
        }

        return [$namespace, $short];
    }

    private function targetRoot(): string
    {
        $path = $this->option('path');

        if (is_string($path) && trim($path) !== '') {
            return rtrim($path, DIRECTORY_SEPARATOR);
        }

        if (function_exists('app_path')) {
            return app_path('Preview/Providers');
        }

        return getcwd().DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Preview'.DIRECTORY_SEPARATOR.'Providers';
    }

    private function ensureDirectory(string $path): void
    {
        if (! is_dir($path) && ! mkdir($path, 0775, true) && ! is_dir($path)) {
            throw new RuntimeException("Directory [{$path}] could not be created.");
        }
    }

    private function studly(string $value): string
    {
        $words = preg_split('/[-_]+/', $value) ?: [$value];

        return implode('', array_map(
            static fn (string $word): string => ucfirst($word),
            array_filter($words, static fn (string $word): bool => $word !== ''),
        ));
    }

    private function contents(string $namespace, string $class, string $name): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

use Oxhq\\Preview\\Capture\\PreviewRequest;
use Oxhq\\Preview\\Providers\\PreviewProvider;
use Oxhq\\Preview\\Providers\\ProviderCapability;
use Oxhq\\Preview\\Providers\\VerificationResult;

final class {$class} implements PreviewProvider
{
    public function name(): string
    {
        return '{$name}';
    }

    public function capabilities(): array
    {
        return [
            ProviderCapability::ExtractsEventType,
            ProviderCapability::GeneratesFixture,
            ProviderCapability::GeneratesTest,
        ];
    }

    public function verify(PreviewRequest \$request): VerificationResult
    {
        return VerificationResult::failed('Not implemented.');
    }

    public function eventType(PreviewRequest \$request): ?string
    {
        return null;
    }

    public function fixtureName(PreviewRequest \$request): string
    {
        return '{$name}-event';
    }

    public function fixtureContext(PreviewRequest \$request): array
    {
        return [];
    }

    public function canSign(): bool
    {
        return false;
    }

    public function sign(string \$rawBody, array \$headers = []): array
    {
        return \$headers;
    }
}
PHP;
    }
}
