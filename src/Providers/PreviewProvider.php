<?php

declare(strict_types=1);

namespace Oxhq\Preview\Providers;

use Oxhq\Preview\Capture\PreviewRequest;

interface PreviewProvider
{
    public function name(): string;

    /**
     * @return list<ProviderCapability>
     */
    public function capabilities(): array;

    public function verify(PreviewRequest $request): VerificationResult;

    public function eventType(PreviewRequest $request): ?string;

    public function fixtureName(PreviewRequest $request): string;

    /**
     * @return array<string, mixed>
     */
    public function fixtureContext(PreviewRequest $request): array;

    public function canSign(): bool;

    /**
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    public function sign(string $rawBody, array $headers = []): array;
}
