<?php

declare(strict_types=1);

namespace Oxhq\Preview\Commands;

use Illuminate\Console\Command;
use Oxhq\Preview\Capture\CaptureRepository;
use Oxhq\Preview\Capture\PreviewRequest;
use Oxhq\Preview\Core\ProviderRegistry;
use Oxhq\Preview\Providers\GenericHmacProvider;
use Oxhq\Preview\Providers\PreviewProvider;
use Throwable;

final class CaptureCommand extends Command
{
    protected $signature = 'preview:capture
        {provider : Provider name registered with Laravel Preview}
        {--method=POST : HTTP method to capture}
        {--path=/ : Request path to capture}
        {--body= : Raw request body}
        {--header=* : Header as "Name: value"; may be repeated}
        {--query=* : Query value as "name=value"; may be repeated}
        {--signature-header= : Required for hmac provider captures}
        {--live : Require preview.live_enabled before allowing live capture}';

    protected $description = 'Create a local v0.1 Preview capture from CLI-supplied request data.';

    public function __construct(
        private readonly ProviderRegistry $providers,
        private readonly CaptureRepository $captures,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if ($this->option('live') && ! (bool) config('preview.live_enabled', false)) {
            $this->error('Live capture is disabled. Enable preview.live_enabled and pass --live explicitly.');

            return self::FAILURE;
        }

        $requestedProvider = (string) $this->argument('provider');
        $providerName = $requestedProvider === 'hmac' ? 'generic-hmac' : $requestedProvider;

        if ($requestedProvider === 'hmac' && (string) $this->option('signature-header') === '') {
            $this->error('The hmac provider requires --signature-header.');

            return self::FAILURE;
        }

        try {
            $provider = $this->provider($providerName);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $path = (string) $this->option('path');

        if ($path === '' || $path[0] !== '/') {
            $this->error('The --path option must start with /.');

            return self::FAILURE;
        }

        $record = $this->captures->store(
            PreviewRequest::make(
                provider: $providerName,
                method: (string) $this->option('method'),
                path: $path,
                query: $this->keyValueOptions((array) $this->option('query')),
                headers: $this->headerOptions((array) $this->option('header')),
                rawBody: (string) $this->option('body'),
            ),
            $provider,
        );

        $this->info("Captured [{$record->id}] for provider [{$record->provider}].");
        $this->line("Endpoint: {$record->method} {$record->path}");
        $this->line('Verification: '.($record->verified ? 'verified' : 'not verified'));

        if ($record->verificationMessage !== null) {
            $this->line("Verification message: {$record->verificationMessage}");
        }

        return self::SUCCESS;
    }

    private function provider(string $providerName): PreviewProvider
    {
        if ($providerName === 'hmac') {
            return new GenericHmacProvider(
                (string) $this->option('signature-header'),
                (string) config('preview.hmac.secret', 'preview-secret'),
                (string) config('preview.hmac.algorithm', 'sha256'),
            );
        }

        return $this->providers->get($providerName);
    }

    /**
     * @param list<string> $values
     * @return array<string, string>
     */
    private function headerOptions(array $values): array
    {
        $headers = [];

        foreach ($values as $value) {
            if (str_contains($value, ':')) {
                [$name, $headerValue] = explode(':', $value, 2);
            } else {
                [$name, $headerValue] = explode('=', $value, 2) + [1 => ''];
            }

            $name = trim($name);

            if ($name !== '') {
                $headers[$name] = trim($headerValue);
            }
        }

        return $headers;
    }

    /**
     * @param list<string> $values
     * @return array<string, string>
     */
    private function keyValueOptions(array $values): array
    {
        $items = [];

        foreach ($values as $value) {
            [$name, $itemValue] = explode('=', $value, 2) + [1 => ''];
            $name = trim($name);

            if ($name !== '') {
                $items[$name] = trim($itemValue);
            }
        }

        return $items;
    }
}
