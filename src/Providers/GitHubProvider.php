<?php

declare(strict_types=1);

namespace Oxhq\Preview\Providers;

use Oxhq\Preview\Capture\PreviewRequest;

class GitHubProvider extends GenericProvider
{
    private const EVENT_HEADER = 'X-GitHub-Event';
    private const SIGNATURE_HEADER = 'X-Hub-Signature-256';
    private const SIGNATURE_PREFIX = 'sha256=';

    public function __construct(
        private readonly string $webhookSecret,
    ) {
    }

    public function name(): string
    {
        return 'github';
    }

    public function capabilities(): array
    {
        return [
            ProviderCapability::VerifiesSignature,
            ProviderCapability::ExtractsEventType,
            ProviderCapability::ReSignsPayload,
            ProviderCapability::GeneratesFixture,
            ProviderCapability::GeneratesTest,
        ];
    }

    public function verify(PreviewRequest $request): VerificationResult
    {
        $signature = $this->header($this->headers($request), self::SIGNATURE_HEADER);

        if ($signature === null || $signature === '') {
            return VerificationResult::failed('Missing X-Hub-Signature-256 header.');
        }

        if (! str_starts_with($signature, self::SIGNATURE_PREFIX)) {
            return VerificationResult::failed('Invalid GitHub signature.');
        }

        $expected = $this->signature($this->rawBody($request));

        if (! hash_equals($expected, $signature)) {
            return VerificationResult::failed('Invalid GitHub signature.');
        }

        return VerificationResult::verified();
    }

    public function eventType(PreviewRequest $request): ?string
    {
        return $this->header($this->headers($request), self::EVENT_HEADER);
    }

    public function fixtureName(PreviewRequest $request): string
    {
        return 'github-' . $this->slug($this->eventType($request) ?? 'event');
    }

    public function canSign(): bool
    {
        return true;
    }

    public function sign(string $rawBody, array $headers = []): array
    {
        $headers[self::SIGNATURE_HEADER] = $this->signature($rawBody);

        return $headers;
    }

    private function signature(string $rawBody): string
    {
        return self::SIGNATURE_PREFIX . hash_hmac('sha256', $rawBody, $this->webhookSecret);
    }
}
