<?php

declare(strict_types=1);

namespace Oxhq\Preview\Providers;

final readonly class VerificationResult
{
    public function __construct(
        public bool $verified,
        public ?string $message = null,
    ) {
    }

    public static function verified(?string $message = null): self
    {
        return new self(true, $message);
    }

    public static function failed(string $message): self
    {
        return new self(false, $message);
    }

    public static function skipped(?string $message = null): self
    {
        return new self(false, $message ?? 'Signature verification was not configured for this provider.');
    }
}
