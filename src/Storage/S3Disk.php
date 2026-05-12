<?php

declare(strict_types=1);

namespace Aether\Storage;

/**
 * S3-compatible storage disk. Works with AWS S3, MinIO, DigitalOcean Spaces.
 *
 * Uses raw HTTP requests instead of the 50MB AWS SDK.
 * Signs requests with AWS Signature V4 manually because I refuse
 * to pull in a dependency just to PUT a file.
 *
 * @package Aether\Storage
 */
final class S3Disk implements DiskInterface
{
    private string $bucket;
    private string $region;
    private string $key;
    private string $secret;
    private string $endpoint;
    private string $baseUrl;

    /**
     * @param array<string, string> $config
     */
    public function __construct(array $config)
    {
        $this->bucket = $config['bucket'] ?? '';
        $this->region = $config['region'] ?? 'us-east-1';
        $this->key = $config['key'] ?? '';
        $this->secret = $config['secret'] ?? '';
        $this->endpoint = rtrim($config['endpoint'] ?? "https://s3.{$this->region}.amazonaws.com", '/');
        $this->baseUrl = $config['url'] ?? "{$this->endpoint}/{$this->bucket}";
    }

    public function put(string $path, string $contents): bool
    {
        $path = ltrim($path, '/');
        $url = "{$this->endpoint}/{$this->bucket}/{$path}";
        $headers = $this->sign('PUT', "/{$this->bucket}/{$path}", $contents);
        $headers['Content-Length'] = (string)strlen($contents);

        $response = $this->request('PUT', $url, $headers, $contents);
        return $response['status'] >= 200 && $response['status'] < 300;
    }

    public function get(string $path): ?string
    {
        $path = ltrim($path, '/');
        $url = "{$this->endpoint}/{$this->bucket}/{$path}";
        $headers = $this->sign('GET', "/{$this->bucket}/{$path}");

        $response = $this->request('GET', $url, $headers);
        return $response['status'] === 200 ? $response['body'] : null;
    }

    public function exists(string $path): bool
    {
        $path = ltrim($path, '/');
        $url = "{$this->endpoint}/{$this->bucket}/{$path}";
        $headers = $this->sign('HEAD', "/{$this->bucket}/{$path}");

        $response = $this->request('HEAD', $url, $headers);
        return $response['status'] === 200;
    }

    public function delete(string $path): bool
    {
        $path = ltrim($path, '/');
        $url = "{$this->endpoint}/{$this->bucket}/{$path}";
        $headers = $this->sign('DELETE', "/{$this->bucket}/{$path}");

        $response = $this->request('DELETE', $url, $headers);
        return $response['status'] >= 200 && $response['status'] < 300;
    }

    /** @return string[] */
    public function files(string $directory = ''): array
    {
        $prefix = $directory !== '' ? ltrim($directory, '/') . '/' : '';
        $url = "{$this->endpoint}/{$this->bucket}?list-type=2&prefix=" . urlencode($prefix);
        $headers = $this->sign('GET', "/{$this->bucket}");

        $response = $this->request('GET', $url, $headers);
        if ($response['status'] !== 200) return [];

        // Parse XML without SimpleXML (keeping it minimal)
        $files = [];
        $body = $response['body'];
        $offset = 0;
        while (($start = strpos($body, '<Key>', $offset)) !== false) {
            $start += 5;
            $end = strpos($body, '</Key>', $start);
            if ($end === false) break;
            $files[] = substr($body, $start, $end - $start);
            $offset = $end + 6;
        }

        return $files;
    }

    public function size(string $path): int
    {
        $path = ltrim($path, '/');
        $url = "{$this->endpoint}/{$this->bucket}/{$path}";
        $headers = $this->sign('HEAD', "/{$this->bucket}/{$path}");

        $response = $this->request('HEAD', $url, $headers);
        return (int)($response['headers']['content-length'] ?? 0);
    }

    public function url(string $path): string
    {
        return $this->baseUrl . '/' . ltrim($path, '/');
    }

    public function putStream(string $path, mixed $stream): bool
    {
        // Read the stream into memory for signing. For truly huge files
        // you'd want multipart upload, but that's a v2 feature.
        $contents = stream_get_contents($stream);
        if ($contents === false) return false;
        return $this->put($path, $contents);
    }

    // ── AWS Signature V4 ──

    /**
     * Sign a request with AWS Signature Version 4.
     * I wrote this by hand so you don't need the AWS SDK.
     *
     * @return array<string, string>
     */
    private function sign(string $method, string $uri, string $payload = ''): array
    {
        $now = gmdate('Ymd\THis\Z');
        $date = gmdate('Ymd');
        $payloadHash = hash('sha256', $payload);

        $headers = [
            'Host' => $this->getHost(),
            'x-amz-date' => $now,
            'x-amz-content-sha256' => $payloadHash,
        ];

        // Canonical request
        $signedHeaders = 'host;x-amz-content-sha256;x-amz-date';
        $canonical = implode("\n", [
            $method,
            $uri,
            '', // query string (empty for simple ops)
            "host:{$headers['Host']}\nx-amz-content-sha256:{$payloadHash}\nx-amz-date:{$now}\n",
            $signedHeaders,
            $payloadHash,
        ]);

        $scope = "{$date}/{$this->region}/s3/aws4_request";
        $stringToSign = "AWS4-HMAC-SHA256\n{$now}\n{$scope}\n" . hash('sha256', $canonical);

        // Signing key
        $kDate = hash_hmac('sha256', $date, 'AWS4' . $this->secret, true);
        $kRegion = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);

        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        $headers['Authorization'] = "AWS4-HMAC-SHA256 Credential={$this->key}/{$scope}, "
            . "SignedHeaders={$signedHeaders}, Signature={$signature}";

        return $headers;
    }

    private function getHost(): string
    {
        $parsed = parse_url($this->endpoint);
        return $parsed['host'] ?? '';
    }

    /**
     * Raw HTTP request using streams. No curl dependency.
     *
     * @return array{status: int, body: string, headers: array<string, string>}
     */
    private function request(string $method, string $url, array $headers, string $body = ''): array
    {
        $headerLines = [];
        foreach ($headers as $k => $v) {
            $headerLines[] = "{$k}: {$v}";
        }

        $opts = [
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headerLines),
                'content' => $body,
                'ignore_errors' => true,
                'timeout' => 30,
            ],
            'ssl' => [
                'verify_peer' => true,
            ],
        ];

        $ctx = stream_context_create($opts);
        $result = @file_get_contents($url, false, $ctx);

        $status = 0;
        $responseHeaders = [];

        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $line) {
                if (str_starts_with($line, 'HTTP/')) {
                    $parts = explode(' ', $line, 3);
                    $status = (int)($parts[1] ?? 0);
                } else {
                    $colonPos = strpos($line, ':');
                    if ($colonPos !== false) {
                        $key = strtolower(trim(substr($line, 0, $colonPos)));
                        $val = trim(substr($line, $colonPos + 1));
                        $responseHeaders[$key] = $val;
                    }
                }
            }
        }

        return [
            'status' => $status,
            'body' => $result !== false ? $result : '',
            'headers' => $responseHeaders,
        ];
    }
}
