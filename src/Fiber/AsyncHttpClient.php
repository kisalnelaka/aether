<?php

declare(strict_types=1);

namespace Aether\Fiber;

/**
 * Fiber-aware async HTTP client.
 *
 * Performs non-blocking HTTP requests using stream sockets.
 * Suspends the calling Fiber during network I/O and yields
 * to the scheduler, enabling concurrent outbound requests.
 *
 * @package Aether\Fiber
 */
final class AsyncHttpClient
{
    private Scheduler $scheduler;
    /** @var array<string, string> Default headers */
    private array $defaultHeaders;
    private float $timeout;

    public function __construct(
        Scheduler $scheduler,
        float $timeout = 30.0,
        array $defaultHeaders = [],
    ) {
        $this->scheduler = $scheduler;
        $this->timeout = $timeout;
        $this->defaultHeaders = array_merge([
            'User-Agent' => 'Aether/1.0',
            'Accept' => '*/*',
            'Connection' => 'close',
        ], $defaultHeaders);
    }

    /**
     * Perform an async GET request.
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public function get(string $url, array $headers = []): array
    {
        return $this->request('GET', $url, '', $headers);
    }

    /**
     * Perform an async POST request.
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public function post(string $url, string $body = '', array $headers = []): array
    {
        return $this->request('POST', $url, $body, $headers);
    }

    /**
     * Perform an async PUT request.
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public function put(string $url, string $body = '', array $headers = []): array
    {
        return $this->request('PUT', $url, $body, $headers);
    }

    /**
     * Perform an async DELETE request.
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public function delete(string $url, array $headers = []): array
    {
        return $this->request('DELETE', $url, '', $headers);
    }

    /**
     * Perform an async JSON POST request.
     *
     * @param mixed $data Data to JSON-encode
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public function postJson(string $url, mixed $data, array $headers = []): array
    {
        $headers['Content-Type'] = 'application/json';
        $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        return $this->request('POST', $url, $body, $headers);
    }

    /**
     * Core async request method.
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public function request(string $method, string $url, string $body = '', array $headers = []): array
    {
        $parsed = $this->parseUrl($url);
        $allHeaders = array_merge($this->defaultHeaders, $headers);
        $allHeaders['Host'] = $parsed['host'];

        if ($body !== '') {
            $allHeaders['Content-Length'] = (string)strlen($body);
        }

        // Build raw HTTP request
        $path = $parsed['path'] . ($parsed['query'] !== '' ? '?' . $parsed['query'] : '');
        $raw = "{$method} {$path} HTTP/1.1\r\n";
        foreach ($allHeaders as $name => $value) {
            $raw .= "{$name}: {$value}\r\n";
        }
        $raw .= "\r\n";
        if ($body !== '') {
            $raw .= $body;
        }

        // Open non-blocking socket
        $scheme = $parsed['scheme'] === 'https' ? 'ssl' : 'tcp';
        $address = "{$scheme}://{$parsed['host']}:{$parsed['port']}";
        $errno = 0;
        $errstr = '';

        $context = stream_context_create([
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);

        $socket = @stream_socket_client(
            $address,
            $errno,
            $errstr,
            $this->timeout,
            STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT,
            $context,
        );

        if ($socket === false) {
            throw new \RuntimeException("HTTP connect failed to {$address}: [{$errno}] {$errstr}");
        }

        stream_set_blocking($socket, false);

        // Wait for socket to be writable (Fiber-suspend)
        $fiber = \Fiber::getCurrent();
        if ($fiber !== null) {
            $this->scheduler->watchSocket($socket, 'write');
            \Fiber::suspend();
        }

        // Send request
        $written = 0;
        $rawLen = strlen($raw);
        while ($written < $rawLen) {
            $bytes = @fwrite($socket, substr($raw, $written));
            if ($bytes === false) break;
            $written += $bytes;
        }

        // Wait for response (Fiber-suspend)
        if ($fiber !== null) {
            $this->scheduler->watchSocket($socket, 'read');
            \Fiber::suspend();
        }

        // Read response
        $response = '';
        while (!feof($socket)) {
            $chunk = @fread($socket, 8192);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $response .= $chunk;
        }

        fclose($socket);

        return $this->parseResponse($response);
    }

    /**
     * @return array{scheme: string, host: string, port: int, path: string, query: string}
     */
    private function parseUrl(string $url): array
    {
        $scheme = 'http';
        $host = '';
        $port = 80;
        $path = '/';
        $query = '';

        // Extract scheme
        $schemeSep = strpos($url, '://');
        if ($schemeSep !== false) {
            $scheme = substr($url, 0, $schemeSep);
            $url = substr($url, $schemeSep + 3);
        }

        if ($scheme === 'https') {
            $port = 443;
        }

        // Extract path
        $pathStart = strpos($url, '/');
        if ($pathStart !== false) {
            $path = substr($url, $pathStart);
            $url = substr($url, 0, $pathStart);
        }

        // Extract query from path
        $queryStart = strpos($path, '?');
        if ($queryStart !== false) {
            $query = substr($path, $queryStart + 1);
            $path = substr($path, 0, $queryStart);
        }

        // Extract host:port
        $portSep = strpos($url, ':');
        if ($portSep !== false) {
            $host = substr($url, 0, $portSep);
            $port = (int)substr($url, $portSep + 1);
        } else {
            $host = $url;
        }

        return compact('scheme', 'host', 'port', 'path', 'query');
    }

    /**
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function parseResponse(string $raw): array
    {
        $headerEnd = strpos($raw, "\r\n\r\n");
        if ($headerEnd === false) {
            return ['status' => 0, 'headers' => [], 'body' => $raw];
        }

        $headerSection = substr($raw, 0, $headerEnd);
        $body = substr($raw, $headerEnd + 4);
        $lines = explode("\r\n", $headerSection);

        // Parse status line
        $statusLine = array_shift($lines) ?? '';
        $status = 0;
        $spacePos = strpos($statusLine, ' ');
        if ($spacePos !== false) {
            $statusStr = substr($statusLine, $spacePos + 1, 3);
            $status = (int)$statusStr;
        }

        // Parse headers
        $headers = [];
        foreach ($lines as $line) {
            $colonPos = strpos($line, ':');
            if ($colonPos !== false) {
                $name = trim(substr($line, 0, $colonPos));
                $value = trim(substr($line, $colonPos + 1));
                $headers[$name] = $value;
            }
        }

        return ['status' => $status, 'headers' => $headers, 'body' => $body];
    }
}
