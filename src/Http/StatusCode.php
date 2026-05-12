<?php

declare(strict_types=1);

namespace Aether\Http;

/**
 * HTTP Status Codes — constant map for zero-lookup overhead.
 *
 * @package Aether\Http
 */
final class StatusCode
{
    // 1xx Informational
    public const CONTINUE = 100;
    public const SWITCHING_PROTOCOLS = 101;
    public const PROCESSING = 102;

    // 2xx Success
    public const OK = 200;
    public const CREATED = 201;
    public const ACCEPTED = 202;
    public const NO_CONTENT = 204;
    public const RESET_CONTENT = 205;
    public const PARTIAL_CONTENT = 206;

    // 3xx Redirection
    public const MOVED_PERMANENTLY = 301;
    public const FOUND = 302;
    public const SEE_OTHER = 303;
    public const NOT_MODIFIED = 304;
    public const TEMPORARY_REDIRECT = 307;
    public const PERMANENT_REDIRECT = 308;

    // 4xx Client Error
    public const BAD_REQUEST = 400;
    public const UNAUTHORIZED = 401;
    public const FORBIDDEN = 403;
    public const NOT_FOUND = 404;
    public const METHOD_NOT_ALLOWED = 405;
    public const NOT_ACCEPTABLE = 406;
    public const REQUEST_TIMEOUT = 408;
    public const CONFLICT = 409;
    public const GONE = 410;
    public const UNPROCESSABLE_ENTITY = 422;
    public const TOO_MANY_REQUESTS = 429;

    // 5xx Server Error
    public const INTERNAL_SERVER_ERROR = 500;
    public const NOT_IMPLEMENTED = 501;
    public const BAD_GATEWAY = 502;
    public const SERVICE_UNAVAILABLE = 503;
    public const GATEWAY_TIMEOUT = 504;

    /** @var array<int, string> */
    private const PHRASES = [
        100 => 'Continue', 101 => 'Switching Protocols', 102 => 'Processing',
        200 => 'OK', 201 => 'Created', 202 => 'Accepted', 204 => 'No Content',
        205 => 'Reset Content', 206 => 'Partial Content',
        301 => 'Moved Permanently', 302 => 'Found', 303 => 'See Other',
        304 => 'Not Modified', 307 => 'Temporary Redirect', 308 => 'Permanent Redirect',
        400 => 'Bad Request', 401 => 'Unauthorized', 403 => 'Forbidden',
        404 => 'Not Found', 405 => 'Method Not Allowed', 406 => 'Not Acceptable',
        408 => 'Request Timeout', 409 => 'Conflict', 410 => 'Gone',
        422 => 'Unprocessable Entity', 429 => 'Too Many Requests',
        500 => 'Internal Server Error', 501 => 'Not Implemented',
        502 => 'Bad Gateway', 503 => 'Service Unavailable', 504 => 'Gateway Timeout',
    ];

    public static function phrase(int $code): string
    {
        return self::PHRASES[$code] ?? 'Unknown';
    }

    public static function isValid(int $code): bool
    {
        return $code >= 100 && $code < 600;
    }

    public static function isSuccess(int $code): bool
    {
        return $code >= 200 && $code < 300;
    }

    public static function isRedirect(int $code): bool
    {
        return $code >= 300 && $code < 400;
    }

    public static function isClientError(int $code): bool
    {
        return $code >= 400 && $code < 500;
    }

    public static function isServerError(int $code): bool
    {
        return $code >= 500 && $code < 600;
    }
}
