<?php

declare(strict_types=1);

namespace Oxhq\Preview\Providers;

use Oxhq\Preview\Capture\PreviewRequest;

class StripeProvider implements PreviewProvider
{
    public function __construct(
        private readonly string $endpointSecret,
        private readonly int $toleranceSeconds = 300,
    ) {
    }

    public function name(): string
    {
        return 'stripe';
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
        $signatureHeader = $this->header($this->headers($request), 'Stripe-Signature');

        if ($signatureHeader === null || $signatureHeader === '') {
            return VerificationResult::failed('Missing Stripe-Signature header.');
        }

        $parts = $this->parseSignatureHeader($signatureHeader);
        $timestamp = isset($parts['t'][0]) ? (int) $parts['t'][0] : null;
        $signatures = $parts['v1'] ?? [];

        if ($timestamp === null || $timestamp <= 0) {
            return VerificationResult::failed('Missing Stripe signature timestamp.');
        }

        if ($signatures === []) {
            return VerificationResult::failed('Missing Stripe v1 signature.');
        }

        if (abs(time() - $timestamp) > $this->toleranceSeconds) {
            return VerificationResult::failed('Stripe signature timestamp is outside the allowed tolerance.');
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $this->rawBody($request), $this->endpointSecret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return VerificationResult::verified();
            }
        }

        return VerificationResult::failed('Invalid Stripe signature.');
    }

    public function eventType(PreviewRequest $request): ?string
    {
        $payload = json_decode($this->rawBody($request), true);

        return is_array($payload) && isset($payload['type']) && is_string($payload['type'])
            ? $payload['type']
            : null;
    }

    public function fixtureName(PreviewRequest $request): string
    {
        $eventType = $this->eventType($request) ?? 'event';

        return 'stripe-' . str_replace('.', '-', $eventType);
    }

    public function fixtureContext(PreviewRequest $request): array
    {
        return [];
    }

    public function canSign(): bool
    {
        return true;
    }

    public function sign(string $rawBody, array $headers = []): array
    {
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $rawBody, $this->endpointSecret);
        $headers['Stripe-Signature'] = sprintf('t=%d,v1=%s', $timestamp, $signature);

        return $headers;
    }

    /**
     * @param array<string, mixed> $headers
     */
    private function header(array $headers, string $name): ?string
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

    /**
     * @return array<string, list<string>>
     */
    private function parseSignatureHeader(string $signatureHeader): array
    {
        $parts = [];

        foreach (explode(',', $signatureHeader) as $pair) {
            [$key, $value] = array_pad(explode('=', trim($pair), 2), 2, null);

            if ($key === '' || $value === null) {
                continue;
            }

            $parts[$key][] = $value;
        }

        return $parts;
    }

    /**
     * @return array<string, mixed>
     */
    private function headers(PreviewRequest $request): array
    {
        if (method_exists($request, 'headers')) {
            return $request->headers();
        }

        return $request->headers;
    }

    private function rawBody(PreviewRequest $request): string
    {
        if (method_exists($request, 'rawBody')) {
            return $request->rawBody();
        }

        return $request->rawBody;
    }
}
