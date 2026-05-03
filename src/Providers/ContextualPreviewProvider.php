<?php

declare(strict_types=1);

namespace Oxhq\Preview\Providers;

interface ContextualPreviewProvider extends PreviewProvider
{
    /**
     * @param array<string, mixed> $context
     */
    public function withRuntimeContext(array $context): PreviewProvider;
}
