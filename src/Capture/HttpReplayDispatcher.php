<?php

declare(strict_types=1);

namespace Oxhq\Preview\Capture;

use Closure;
use RuntimeException;

final class HttpReplayDispatcher
{
    private ?Closure $transport;

    public function __construct(?callable $transport = null)
    {
        $this->transport = $transport === null ? null : $transport(...);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function dispatch(array $payload, string $target): ReplayResult
    {
        $url = $this->urlFor($payload, $target);
        $method = strtoupper((string) ($payload['method'] ?? 'POST'));
        $headers = $this->stringHeaders((array) ($payload['headers'] ?? []));
        $body = (string) ($payload['raw_body'] ?? '');

        if ($this->transport !== null) {
            $result = ($this->transport)($url, $method, $headers, $body, $payload);

            if (! $result instanceof ReplayResult) {
                throw new RuntimeException('Replay transport must return a ReplayResult instance.');
            }

            return $result;
        }

        return $this->send($url, $method, $headers, $body);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function urlFor(array $payload, string $target): string
    {
        $target = trim($target);

        if ($target === '') {
            throw new RuntimeException('Replay target URL cannot be empty.');
        }

        $parts = parse_url($target);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            throw new RuntimeException("Replay target [{$target}] must be an absolute URL.");
        }

        $targetPath = (string) ($parts['path'] ?? '');
        $isBaseUrl = $targetPath === '' || $targetPath === '/';
        $path = $isBaseUrl ? $this->joinPaths($targetPath, (string) ($payload['path'] ?? '/')) : $targetPath;
        $query = $isBaseUrl ? $this->queryString((string) ($parts['query'] ?? ''), (array) ($payload['query'] ?? [])) : (string) ($parts['query'] ?? '');

        $url = $parts['scheme'].'://';
        $url .= isset($parts['user']) ? $parts['user'].(isset($parts['pass']) ? ':'.$parts['pass'] : '').'@' : '';
        $url .= $parts['host'];
        $url .= isset($parts['port']) ? ':'.$parts['port'] : '';
        $url .= $path === '' ? '/' : $path;
        $url .= $query === '' ? '' : '?'.$query;
        $url .= isset($parts['fragment']) ? '#'.$parts['fragment'] : '';

        return $url;
    }

    /**
     * @param array<string, mixed> $headers
     * @return list<string>
     */
    private function stringHeaders(array $headers): array
    {
        $lines = [];

        foreach ($headers as $name => $value) {
            if (is_array($value)) {
                foreach ($value as $item) {
                    $lines[] = $name.': '.(string) $item;
                }

                continue;
            }

            $lines[] = $name.': '.(string) $value;
        }

        return $lines;
    }

    /**
     * @param list<string> $headers
     */
    private function send(string $url, string $method, array $headers, string $body): ReplayResult
    {
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'content' => $body,
                'ignore_errors' => true,
            ],
        ]);

        $responseHeaders = [];
        $response = @file_get_contents($url, false, $context);
        $rawResponseHeaders = function_exists('http_get_last_response_headers')
            ? (http_get_last_response_headers() ?: [])
            : (get_defined_vars()['http_response_header'] ?? []);

        if (is_array($rawResponseHeaders)) {
            $responseHeaders = $this->parseResponseHeaders($rawResponseHeaders);
        }

        if ($response === false) {
            throw new RuntimeException("Replay request to [{$url}] failed.");
        }

        return new ReplayResult(
            $this->statusCode(is_array($rawResponseHeaders) ? $rawResponseHeaders : []),
            $response,
            $responseHeaders,
        );
    }

    /**
     * @param list<string> $headers
     */
    private function statusCode(array $headers): int
    {
        foreach ($headers as $line) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/', $line, $matches) === 1) {
                return (int) $matches[1];
            }
        }

        return 0;
    }

    /**
     * @param list<string> $headers
     * @return array<string, list<string>>
     */
    private function parseResponseHeaders(array $headers): array
    {
        $parsed = [];

        foreach ($headers as $line) {
            if (! str_contains($line, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $line, 2);
            $parsed[trim($name)][] = trim($value);
        }

        return $parsed;
    }

    private function joinPaths(string $basePath, string $payloadPath): string
    {
        $base = trim($basePath, '/');
        $path = trim($payloadPath, '/');

        if ($base === '' && $path === '') {
            return '/';
        }

        return '/'.trim($base.'/'.$path, '/');
    }

    /**
     * @param array<string, mixed> $payloadQuery
     */
    private function queryString(string $targetQuery, array $payloadQuery): string
    {
        $payloadQueryString = http_build_query($payloadQuery);

        if ($targetQuery === '') {
            return $payloadQueryString;
        }

        if ($payloadQueryString === '') {
            return $targetQuery;
        }

        return $targetQuery.'&'.$payloadQueryString;
    }
}
