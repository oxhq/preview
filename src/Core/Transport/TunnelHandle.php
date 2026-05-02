<?php

declare(strict_types=1);

namespace Oxhq\Preview\Core\Transport;

class TunnelHandle
{
    public function __construct(
        public readonly string $publicUrl,
        public readonly ?int $processId = null,
        public readonly array $metadata = [],
    ) {
    }
}
