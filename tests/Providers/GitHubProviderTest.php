<?php

declare(strict_types=1);

namespace Oxhq\Preview\Tests\Providers;

use Oxhq\Preview\Capture\PreviewRequest;
use Oxhq\Preview\Providers\GitHubProvider;
use Oxhq\Preview\Providers\ProviderCapability;
use PHPUnit\Framework\TestCase;

final class GitHubProviderTest extends TestCase
{
    public function test_it_verifies_github_signature(): void
    {
        $provider = new GitHubProvider('github_secret');
        $body = '{"zen":"Keep it logically awesome."}';
        $signature = 'sha256=' . hash_hmac('sha256', $body, 'github_secret');

        $result = $provider->verify(PreviewRequest::make(
            provider: 'github',
            method: 'POST',
            path: '/webhook/github',
            headers: ['X-Hub-Signature-256' => $signature],
            rawBody: $body,
        ));

        $this->assertTrue($result->verified);
    }

    public function test_it_rejects_missing_github_signature(): void
    {
        $provider = new GitHubProvider('github_secret');

        $result = $provider->verify(PreviewRequest::make(
            provider: 'github',
            method: 'POST',
            path: '/webhook/github',
            rawBody: '{"zen":"Avoid administrative distraction."}',
        ));

        $this->assertFalse($result->verified);
        $this->assertSame('Missing X-Hub-Signature-256 header.', $result->message);
    }

    public function test_it_rejects_invalid_github_signature(): void
    {
        $provider = new GitHubProvider('github_secret');

        $result = $provider->verify(PreviewRequest::make(
            provider: 'github',
            method: 'POST',
            path: '/webhook/github',
            headers: ['X-Hub-Signature-256' => 'sha256=bad-signature'],
            rawBody: '{"zen":"Speak like a human."}',
        ));

        $this->assertFalse($result->verified);
        $this->assertSame('Invalid GitHub signature.', $result->message);
    }

    public function test_it_extracts_event_type_and_safe_fixture_name(): void
    {
        $provider = new GitHubProvider('github_secret');
        $request = PreviewRequest::make(
            provider: 'github',
            method: 'POST',
            path: '/webhook/github',
            headers: ['X-GitHub-Event' => 'pull_request.review-comment'],
        );

        $this->assertSame('pull_request.review-comment', $provider->eventType($request));
        $this->assertSame('github-pull_request.review-comment', $provider->fixtureName($request));
    }

    public function test_it_signs_payload_with_github_signature_header(): void
    {
        $provider = new GitHubProvider('github_secret');
        $body = '{"zen":"Responsive is better than fast."}';

        $headers = $provider->sign($body, ['Content-Type' => 'application/json']);

        $this->assertTrue($provider->canSign());
        $this->assertSame('application/json', $headers['Content-Type']);
        $this->assertSame(
            'sha256=' . hash_hmac('sha256', $body, 'github_secret'),
            $headers['X-Hub-Signature-256'],
        );
        $this->assertTrue($provider->verify(PreviewRequest::make(
            provider: 'github',
            method: 'POST',
            path: '/webhook/github',
            headers: ['X-Hub-Signature-256' => $headers['X-Hub-Signature-256']],
            rawBody: $body,
        ))->verified);
    }

    public function test_it_reports_github_capabilities(): void
    {
        $provider = new GitHubProvider('github_secret');

        $this->assertSame('github', $provider->name());
        $this->assertSame([
            ProviderCapability::VerifiesSignature,
            ProviderCapability::ExtractsEventType,
            ProviderCapability::ReSignsPayload,
            ProviderCapability::GeneratesFixture,
            ProviderCapability::GeneratesTest,
        ], $provider->capabilities());
    }
}
