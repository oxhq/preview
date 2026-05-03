<?php

declare(strict_types=1);

namespace Oxhq\Preview\Providers;

use Oxhq\Preview\Capture\PreviewRequest;

class ShopifyProvider extends GenericProvider
{
    public function __construct(
        private readonly string $clientSecret,
    ) {
    }

    public function name(): string
    {
        return 'shopify';
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
        $signatureHeader = $this->header($this->headers($request), 'X-Shopify-Hmac-Sha256');

        if ($signatureHeader === null || $signatureHeader === '') {
            return VerificationResult::failed('Missing X-Shopify-Hmac-Sha256 header.');
        }

        $expected = $this->signature($this->rawBody($request));

        if (hash_equals($expected, $signatureHeader)) {
            return VerificationResult::verified();
        }

        return VerificationResult::failed('Invalid Shopify signature.');
    }

    public function eventType(PreviewRequest $request): ?string
    {
        return $this->header($this->headers($request), 'X-Shopify-Topic');
    }

    public function fixtureName(PreviewRequest $request): string
    {
        return 'shopify-' . $this->slug($this->eventType($request) ?? 'event');
    }

    public function canSign(): bool
    {
        return true;
    }

    public function sign(string $rawBody, array $headers = []): array
    {
        $headers['X-Shopify-Hmac-Sha256'] = $this->signature($rawBody);

        return $headers;
    }

    private function signature(string $rawBody): string
    {
        return base64_encode(hash_hmac('sha256', $rawBody, $this->clientSecret, true));
    }
}
