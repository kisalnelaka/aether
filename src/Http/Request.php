<?php

declare(strict_types=1);

namespace Aether\Http;

/**
 * Immutable HTTP Request — wraps superglobals or worker payloads.
 *
 * Designed for resident-memory servers: constructed from raw data
 * (not superglobals) so it can be created per-request without
 * global state pollution. All parsing uses strpos/substr — no regex.
 *
 * @package Aether\Http
 */
final class Request
{
    private string $method;
    private string $uri;
    private string $path;
    private string $queryString;
    /** @var array<string, string> */
    private array $query;
    /** @var array<string, string|array<string>> */
    private array $post;
    /** @var array<string, string> */
    private array $headers;
    /** @var array<string, string> */
    private array $cookies;
    /** @var array<string, string> */
    private array $server;
    private string $body;
    private string $protocol;
    /** @var array<string, string> Route parameters injected after matching */
    private array $routeParams = [];
    /** @var array<string, mixed> Arbitrary attributes bag */
    private array $attributes = [];

    /**
     * @param string $method HTTP method
     * @param string $uri    Full URI including query string
     * @param array<string, string> $headers
     * @param string $body   Raw request body
     * @param array<string, string> $server  Server variables
     * @param array<string, string> $cookies
     * @param array<string, string|array<string>> $post POST data
     */
    public function __construct(
        string $method,
        string $uri,
        array $headers = [],
        string $body = '',
        array $server = [],
        array $cookies = [],
        array $post = [],
        string $protocol = '1.1',
    ) {
        $this->method = strtoupper($method);
        $this->uri = $uri;
        $this->body = $body;
        $this->server = $server;
        $this->cookies = $cookies;
        $this->post = $post;
        $this->protocol = $protocol;

        // Normalize headers to lowercase keys (byte-level, no regex)
        $this->headers = [];
        foreach ($headers as $name => $value) {
            $this->headers[$this->lowerKey($name)] = $value;
        }

        // Parse path and query string using strpos
        $qPos = strpos($uri, '?');
        if ($qPos !== false) {
            $this->path = substr($uri, 0, $qPos);
            $this->queryString = substr($uri, $qPos + 1);
        } else {
            $this->path = $uri;
            $this->queryString = '';
        }

        // Parse query params manually (no regex)
        $this->query = $this->parseQueryString($this->queryString);
    }

    /**
     * Create a Request from PHP superglobals (traditional mode).
     */
    public static function fromGlobals(): self
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $protocol = $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1';
        $protocolVersion = substr($protocol, strpos($protocol, '/') + 1) ?: '1.1';

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (strncmp($key, 'HTTP_', 5) === 0) {
                $name = str_replace('_', '-', substr($key, 5));
                $headers[$name] = (string)$value;
            }
        }

        $body = file_get_contents('php://input') ?: '';

        return new self($method, $uri, $headers, $body, $_SERVER, $_COOKIE, $_POST, $protocolVersion);
    }

    // ── Getters ──

    public function getMethod(): string { return $this->method; }
    public function getUri(): string { return $this->uri; }
    public function getPath(): string { return $this->path; }
    public function getQueryString(): string { return $this->queryString; }
    public function getProtocol(): string { return $this->protocol; }
    public function getBody(): string { return $this->body; }

    public function getHeader(string $name, string $default = ''): string
    {
        return $this->headers[$this->lowerKey($name)] ?? $default;
    }

    /** @return array<string, string> */
    public function getHeaders(): array { return $this->headers; }

    public function hasHeader(string $name): bool
    {
        return isset($this->headers[$this->lowerKey($name)]);
    }

    public function getQuery(string $key, string $default = ''): string
    {
        return $this->query[$key] ?? $default;
    }

    /** @return array<string, string> */
    public function getAllQuery(): array { return $this->query; }

    /** @return string|array<string>|null */
    public function getPost(string $key): string|array|null
    {
        return $this->post[$key] ?? null;
    }

    /** @return array<string, string|array<string>> */
    public function getAllPost(): array { return $this->post; }

    public function getCookie(string $name, string $default = ''): string
    {
        return $this->cookies[$name] ?? $default;
    }

    public function getServer(string $key, string $default = ''): string
    {
        return $this->server[$key] ?? $default;
    }

    // ── Route Params ──

    /** @param array<string, string> $params */
    public function withRouteParams(array $params): self
    {
        $clone = clone $this;
        $clone->routeParams = $params;
        return $clone;
    }

    public function getRouteParam(string $name, string $default = ''): string
    {
        return $this->routeParams[$name] ?? $default;
    }

    /** @return array<string, string> */
    public function getRouteParams(): array { return $this->routeParams; }

    // ── Attributes ──

    public function withAttribute(string $name, mixed $value): self
    {
        $clone = clone $this;
        $clone->attributes[$name] = $value;
        return $clone;
    }

    public function getAttribute(string $name, mixed $default = null): mixed
    {
        return $this->attributes[$name] ?? $default;
    }

    // ── Body Parsing ──

    /**
     * Parse JSON body.
     * @return array<string, mixed>
     */
    public function json(): array
    {
        if ($this->body === '') {
            return [];
        }
        $decoded = json_decode($this->body, true, 128, JSON_THROW_ON_ERROR);
        return is_array($decoded) ? $decoded : [];
    }

    public function getContentType(): string
    {
        return $this->getHeader('content-type');
    }

    public function isJson(): bool
    {
        $ct = $this->getContentType();
        return strpos($ct, 'application/json') !== false;
    }

    public function isAjax(): bool
    {
        return $this->getHeader('x-requested-with') === 'XMLHttpRequest';
    }

    public function getClientIp(): string
    {
        return $this->getHeader('x-forwarded-for')
            ?: $this->getHeader('x-real-ip')
            ?: $this->getServer('REMOTE_ADDR', '127.0.0.1');
    }

    // ── Private Helpers (zero regex) ──

    private function lowerKey(string $key): string
    {
        $result = '';
        $len = strlen($key);
        for ($i = 0; $i < $len; $i++) {
            $c = ord($key[$i]);
            $result .= ($c >= 65 && $c <= 90) ? chr($c + 32) : $key[$i];
        }
        return $result;
    }

    /** @return array<string, string> */
    private function parseQueryString(string $qs): array
    {
        if ($qs === '') return [];
        $params = [];
        $pairs = explode('&', $qs);
        foreach ($pairs as $pair) {
            $eqPos = strpos($pair, '=');
            if ($eqPos !== false) {
                $key = urldecode(substr($pair, 0, $eqPos));
                $val = urldecode(substr($pair, $eqPos + 1));
                $params[$key] = $val;
            } else {
                $params[urldecode($pair)] = '';
            }
        }
        return $params;
    }
}
