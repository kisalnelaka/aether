<?php

declare(strict_types=1);

namespace Aether\Http;

/**
 * Mutable HTTP Response builder.
 *
 * Accumulates headers, cookies, and body content. Designed
 * for both traditional CGI output and worker-based streaming.
 *
 * @package Aether\Http
 */
final class Response
{
    private int $statusCode;
    private string $reasonPhrase;
    /** @var array<string, array<string>> */
    private array $headers = [];
    private string $body;
    /** @var array<string> Set-Cookie header lines */
    private array $cookies = [];
    private string $protocol = '1.1';

    public function __construct(string $body = '', int $statusCode = 200, array $headers = [])
    {
        $this->body = $body;
        $this->statusCode = $statusCode;
        $this->reasonPhrase = StatusCode::phrase($statusCode);
        foreach ($headers as $name => $value) {
            $this->headers[strtolower($name)] = is_array($value) ? $value : [$value];
        }
    }

    // ── Static Factories ──

    public static function json(mixed $data, int $status = 200, int $flags = JSON_UNESCAPED_UNICODE): self
    {
        $body = json_encode($data, $flags | JSON_THROW_ON_ERROR);
        return (new self($body, $status))
            ->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    public static function html(string $html, int $status = 200): self
    {
        return (new self($html, $status))
            ->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public static function text(string $text, int $status = 200): self
    {
        return (new self($text, $status))
            ->withHeader('Content-Type', 'text/plain; charset=utf-8');
    }

    public static function redirect(string $url, int $status = 302): self
    {
        return (new self('', $status))
            ->withHeader('Location', $url);
    }

    public static function noContent(): self
    {
        return new self('', 204);
    }

    public static function notFound(string $message = 'Not Found'): self
    {
        return self::json(['error' => $message], 404);
    }

    public static function error(string $message = 'Internal Server Error', int $status = 500): self
    {
        return self::json(['error' => $message], $status);
    }

    // ── Fluent Setters ──

    public function withStatus(int $code, string $reason = ''): self
    {
        $this->statusCode = $code;
        $this->reasonPhrase = $reason !== '' ? $reason : StatusCode::phrase($code);
        return $this;
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[strtolower($name)] = [$value];
        return $this;
    }

    public function withAddedHeader(string $name, string $value): self
    {
        $key = strtolower($name);
        if (!isset($this->headers[$key])) {
            $this->headers[$key] = [];
        }
        $this->headers[$key][] = $value;
        return $this;
    }

    public function withoutHeader(string $name): self
    {
        unset($this->headers[strtolower($name)]);
        return $this;
    }

    public function withBody(string $body): self
    {
        $this->body = $body;
        return $this;
    }

    public function appendBody(string $content): self
    {
        $this->body .= $content;
        return $this;
    }

    public function withCookie(
        string $name,
        string $value,
        int $maxAge = 0,
        string $path = '/',
        string $domain = '',
        bool $secure = false,
        bool $httpOnly = true,
        string $sameSite = 'Lax',
    ): self {
        $cookie = urlencode($name) . '=' . urlencode($value);
        if ($maxAge > 0) { $cookie .= '; Max-Age=' . $maxAge; }
        if ($path !== '') { $cookie .= '; Path=' . $path; }
        if ($domain !== '') { $cookie .= '; Domain=' . $domain; }
        if ($secure) { $cookie .= '; Secure'; }
        if ($httpOnly) { $cookie .= '; HttpOnly'; }
        if ($sameSite !== '') { $cookie .= '; SameSite=' . $sameSite; }
        $this->cookies[] = $cookie;
        return $this;
    }

    public function withProtocol(string $protocol): self
    {
        $this->protocol = $protocol;
        return $this;
    }

    // ── Getters ──

    public function getStatusCode(): int { return $this->statusCode; }
    public function getReasonPhrase(): string { return $this->reasonPhrase; }
    public function getBody(): string { return $this->body; }
    public function getBodyLength(): int { return strlen($this->body); }
    public function getProtocol(): string { return $this->protocol; }

    /** @return array<string, array<string>> */
    public function getHeaders(): array { return $this->headers; }

    public function getHeader(string $name): string
    {
        $values = $this->headers[strtolower($name)] ?? [];
        return implode(', ', $values);
    }

    public function hasHeader(string $name): bool
    {
        return isset($this->headers[strtolower($name)]);
    }

    /** @return array<string> */
    public function getCookies(): array { return $this->cookies; }

    // ── Output ──

    /**
     * Send response via traditional PHP (CGI/FPM).
     */
    public function send(): void
    {
        if (!headers_sent()) {
            header("HTTP/{$this->protocol} {$this->statusCode} {$this->reasonPhrase}", true, $this->statusCode);
            foreach ($this->headers as $name => $values) {
                foreach ($values as $i => $value) {
                    header("{$name}: {$value}", $i === 0);
                }
            }
            foreach ($this->cookies as $cookie) {
                header("Set-Cookie: {$cookie}", false);
            }
        }
        echo $this->body;
    }

    /**
     * Serialize to a string for worker-based servers.
     */
    public function toString(): string
    {
        $out = "HTTP/{$this->protocol} {$this->statusCode} {$this->reasonPhrase}\r\n";
        foreach ($this->headers as $name => $values) {
            foreach ($values as $value) {
                $out .= "{$name}: {$value}\r\n";
            }
        }
        foreach ($this->cookies as $cookie) {
            $out .= "Set-Cookie: {$cookie}\r\n";
        }
        $out .= "\r\n";
        $out .= $this->body;
        return $out;
    }
}
