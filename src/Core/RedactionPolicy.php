<?php

declare(strict_types=1);

namespace Oxhq\Preview\Core;

class RedactionPolicy
{
    /** @param list<string> $redactedHeaders */
    public function __construct(private readonly array $redactedHeaders = [])
    {
    }

    /** @param array<string, string|list<string>> $headers */
    public function redactHeaders(array $headers): array
    {
        $redacted = [];
        $deny = array_map('strtolower', $this->redactedHeaders);

        foreach ($headers as $name => $value) {
            $redacted[$name] = in_array(strtolower((string) $name), $deny, true)
                ? '[redacted]'
                : $value;
        }

        return $redacted;
    }
}
