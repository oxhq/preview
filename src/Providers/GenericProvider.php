<?php

declare(strict_types=1);

namespace Oxhq\Preview\Providers;

use Oxhq\Preview\Capture\PreviewRequest;

class GenericProvider implements PreviewProvider
{
    public function name(): string
    {
        return 'generic';
    }

    public function capabilities(): array
    {
        return [
            ProviderCapability::ExtractsEventType,
            ProviderCapability::GeneratesFixture,
            ProviderCapability::GeneratesTest,
        ];
    }

    public function verify(PreviewRequest $request): VerificationResult
    {
        return VerificationResult::skipped('Generic provider does not verify request signatures.');
    }

    public function eventType(PreviewRequest $request): ?string
    {
        return $this->header($this->headers($request), 'X-Preview-Event');
    }

    public function fixtureName(PreviewRequest $request): string
    {
        return $this->slug($this->eventType($request) ?? 'generic-capture');
    }

    public function canSign(): bool
    {
        return false;
    }

    public function sign(string $rawBody, array $headers = []): array
    {
        return $headers;
    }

    /**
     * @param array<string, mixed> $headers
     */
    protected function header(array $headers, string $name): ?string
    {
        foreach ($headers as $header => $value) {
            if (strcasecmp((string) $header, $name) !== 0) {
                continue;
            }

            if (is_array($value)) {
                $value = reset($value);
            }

            return is_scalar($value) ? (string) $value : null;
        }

        return null;
    }

    protected function slug(string $value): string
    {
        $slug = strtolower((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $value));
        $slug = trim($slug, '-');

        return $slug !== '' ? $slug : 'generic-capture';
    }

    /**
     * @return array<string, mixed>
     */
    protected function headers(PreviewRequest $request): array
    {
        if (method_exists($request, 'headers')) {
            return $request->headers();
        }

        return $request->headers;
    }

    protected function rawBody(PreviewRequest $request): string
    {
        if (method_exists($request, 'rawBody')) {
            return $request->rawBody();
        }

        return $request->rawBody;
    }
}
