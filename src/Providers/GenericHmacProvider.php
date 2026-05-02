<?php

declare(strict_types=1);

namespace Oxhq\Preview\Providers;

use Oxhq\Preview\Capture\PreviewRequest;

class GenericHmacProvider extends GenericProvider
{
    public function __construct(
        private readonly string $signatureHeaderName,
        private readonly string $sharedSecret,
        private readonly string $algorithm = 'sha256',
    ) {
    }

    public function name(): string
    {
        return 'hmac';
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
        $signature = $this->header($this->headers($request), $this->signatureHeaderName);

        if ($signature === null || $signature === '') {
            return VerificationResult::failed(sprintf('Missing %s header.', $this->signatureHeaderName));
        }

        $expected = hash_hmac($this->algorithm, $this->rawBody($request), $this->sharedSecret);
        $actual = $this->normalizeSignature($signature);

        if (! hash_equals($expected, $actual)) {
            return VerificationResult::failed('Invalid HMAC signature.');
        }

        return VerificationResult::verified();
    }

    public function fixtureContext(PreviewRequest $request): array
    {
        return [
            'signature_header' => $this->signatureHeaderName,
            'algorithm' => $this->algorithm,
        ];
    }

    public function canSign(): bool
    {
        return true;
    }

    public function sign(string $rawBody, array $headers = []): array
    {
        $headers[$this->signatureHeaderName] = hash_hmac($this->algorithm, $rawBody, $this->sharedSecret);

        return $headers;
    }

    private function normalizeSignature(string $signature): string
    {
        $prefix = $this->algorithm . '=';

        if (str_starts_with($signature, $prefix)) {
            return substr($signature, strlen($prefix));
        }

        return $signature;
    }
}
