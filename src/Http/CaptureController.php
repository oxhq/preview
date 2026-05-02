<?php

declare(strict_types=1);

namespace Oxhq\Preview\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Oxhq\Preview\Capture\CaptureRepository;
use Oxhq\Preview\Capture\PreviewRequest;
use Oxhq\Preview\Core\ProviderRegistry;
use Oxhq\Preview\Providers\GenericHmacProvider;
use Oxhq\Preview\Providers\PreviewProvider;

final class CaptureController
{
    public function __construct(
        private readonly CaptureRepository $captures,
        private readonly ProviderRegistry $providers,
    ) {
    }

    public function __invoke(Request $request, string $provider): JsonResponse
    {
        try {
            $previewProvider = $this->providerFor($request, $provider);
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'error' => $exception->getMessage(),
            ], 404);
        }

        $capture = $this->captures->store(
            PreviewRequest::make(
                provider: $provider,
                method: $request->method(),
                path: $this->capturePath($request),
                query: $request->query->all(),
                headers: $request->headers->all(),
                rawBody: $request->getContent(),
            ),
            $previewProvider,
        );

        return response()->json([
            'id' => $capture->id,
            'provider' => $capture->provider,
            'event_type' => $capture->eventType,
            'verified' => $capture->verified,
            'verification_message' => $capture->verificationMessage,
        ]);
    }

    private function providerFor(Request $request, string $provider): PreviewProvider
    {
        if ($provider === 'hmac' && is_string($request->query('signature_header')) && trim((string) $request->query('signature_header')) !== '') {
            return new GenericHmacProvider(
                trim((string) $request->query('signature_header')),
                (string) config('preview.hmac.secret', 'preview-secret'),
                (string) config('preview.hmac.algorithm', 'sha256'),
            );
        }

        return $this->providers->get($provider);
    }

    private function capturePath(Request $request): string
    {
        $path = $request->headers->get('X-Preview-Original-Path');

        if (! is_string($path) || trim($path) === '') {
            $path = $request->path();
        }

        return '/'.ltrim(trim($path), '/');
    }
}
