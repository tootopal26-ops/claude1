<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Immutable-ish view over the incoming HTTP request.
 */
final class Request
{
    private const MAX_BODY = 1_048_576; // 1 MiB — every JSON endpoint here is tiny

    /** @var array<string,mixed> request-scoped data set by middleware (e.g. the signed-in admin) */
    public array $attributes = [];

    private ?string $raw = null;
    private mixed $json = null;
    private bool $jsonParsed = false;

    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed> $post
     * @param array<string,string> $headers lower-cased names
     * @param array<string,string> $cookies
     * @param array<string,mixed> $server
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $post,
        public readonly array $headers,
        public readonly array $cookies,
        public readonly array $server,
        public readonly array $files = [],
        ?string $rawBody = null,
    ) {
        $this->raw = $rawBody;
    }

    /**
     * Build a request in code (tests, CLI tools).
     *
     * @param array<string,string> $headers
     * @param array<string,string> $cookies
     */
    public static function fake(string $method, string $path, array $headers = [], string $body = '', array $cookies = [], string $ip = '203.0.113.9', array $query = []): self
    {
        $headers = array_change_key_case($headers, CASE_LOWER);
        $headers += ['user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120 Safari/537.36'];
        return new self(strtoupper($method), $path, $query, [], $headers, $cookies, ['REMOTE_ADDR' => $ip], [], $body);
    }

    public static function capture(): self
    {
        $server = $_SERVER;
        $uri = (string) ($server['REQUEST_URI'] ?? '/');
        $path = (string) (parse_url($uri, PHP_URL_PATH) ?? '/');
        $path = rawurldecode($path);
        // collapse // and strip the sub-folder the app lives in
        $path = '/' . trim((string) preg_replace('#/+#', '/', $path), '/');
        $base = base_path();
        if ($base !== '' && ($path === $base || str_starts_with($path, $base . '/'))) {
            $path = substr($path, strlen($base)) ?: '/';
        }
        if ($path === '/index.php') {
            $path = '/';
        }

        $headers = [];
        foreach ($server as $k => $v) {
            if (is_string($v) && str_starts_with($k, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = $v;
            }
        }
        foreach (['CONTENT_TYPE' => 'content-type', 'CONTENT_LENGTH' => 'content-length'] as $sk => $hk) {
            if (isset($server[$sk]) && is_string($server[$sk])) {
                $headers[$hk] = $server[$sk];
            }
        }

        return new self(
            strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET')),
            $path,
            $_GET,
            $_POST,
            $headers,
            array_map('strval', array_filter($_COOKIE, 'is_scalar')),
            $server,
            $_FILES,
        );
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function cookie(string $name): ?string
    {
        return $this->cookies[$name] ?? null;
    }

    public function isSecure(): bool
    {
        if (str_starts_with((string) Env::get('APP_URL', ''), 'https://')) {
            return true; // the configured URL is the source of truth
        }
        $https = $this->server['HTTPS'] ?? '';
        return $https !== '' && $https !== 'off';
    }

    /** Client IP. Only honours a proxy header if the operator explicitly configured one. */
    public function ip(): string
    {
        $ip = (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
        $hdr = (string) Env::get('TRUSTED_PROXY_HEADER', '');
        if ($hdr !== '') {
            $value = $this->header($hdr);
            if ($value !== null && $value !== '') {
                $parts = array_map('trim', explode(',', $value));
                // CF-Connecting-IP is a single value; for X-Forwarded-For the right-most entry
                // is the one our trusted proxy appended (left-most entries are client-controlled).
                $candidate = strtolower($hdr) === 'x-forwarded-for' ? end($parts) : $parts[0];
                if ($candidate !== false && filter_var($candidate, FILTER_VALIDATE_IP)) {
                    $ip = $candidate;
                }
            }
        }
        return $ip;
    }

    public function userAgent(): string
    {
        return substr((string) ($this->header('user-agent') ?? ''), 0, 255);
    }

    public function isJson(): bool
    {
        return stripos((string) $this->header('content-type'), 'application/json') !== false;
    }

    public function wantsJson(): bool
    {
        return str_starts_with($this->path, '/api/') || str_starts_with($this->path, '/admin/api/')
            || stripos((string) $this->header('accept'), 'application/json') === 0
            || $this->header('x-requested-with') === 'smb';
    }

    public function body(): string
    {
        if ($this->raw === null) {
            $len = (int) ($this->header('content-length') ?? 0);
            if ($len > self::MAX_BODY) {
                throw new HttpException(413, 'Request body too large.');
            }
            $this->raw = (string) file_get_contents('php://input', false, null, 0, self::MAX_BODY + 1);
            if (strlen($this->raw) > self::MAX_BODY) {
                throw new HttpException(413, 'Request body too large.');
            }
        }
        return $this->raw;
    }

    /** Decoded JSON object body. Invalid JSON → 400. */
    public function json(): array
    {
        if (!$this->jsonParsed) {
            $this->jsonParsed = true;
            $raw = $this->body();
            if ($raw === '') {
                $this->json = [];
            } else {
                try {
                    $this->json = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    throw new HttpException(400, 'Invalid JSON body.', 'invalid_json');
                }
            }
        }
        return is_array($this->json) ? $this->json : [];
    }

    /** Value from the JSON body or form body. */
    public function input(string $key, mixed $default = null): mixed
    {
        $src = $this->isJson() ? $this->json() : $this->post;
        return $src[$key] ?? $default;
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        return $this->isJson() ? $this->json() : $this->post;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function queryString(string $key, string $default = '', int $max = 200): string
    {
        $v = $this->query[$key] ?? $default;
        return is_scalar($v) ? substr(trim((string) $v), 0, $max) : $default;
    }

    public function queryInt(string $key, int $default = 0, int $min = PHP_INT_MIN, int $max = PHP_INT_MAX): int
    {
        $v = $this->query[$key] ?? null;
        if (!is_scalar($v) || !preg_match('/^-?\d{1,15}$/', (string) $v)) {
            return $default;
        }
        return max($min, min($max, (int) $v));
    }

    /**
     * Same-origin check for state-changing requests (defence in depth on top of
     * SameSite cookies, custom headers and CSRF tokens).
     */
    public function sameOrigin(): bool
    {
        $allowed = parse_url((string) Env::get('APP_URL', ''));
        $host = strtolower((string) ($allowed['host'] ?? ''));
        if ($host === '') {
            return true; // not configured yet (installer)
        }
        $port = $allowed['port'] ?? null;
        $origin = $this->header('origin');
        if ($origin !== null && strtolower(trim($origin)) === 'null') {
            return false;   // opaque origin (sandboxed iframe, data: page, cross-origin redirect): our own pages never send it
        }
        $source = $origin !== null ? $origin : $this->header('referer');
        if ($source === null || $source === '') {
            return true; // privacy tools may strip both; token checks still apply
        }
        $p = parse_url($source);
        if (!is_array($p) || empty($p['host'])) {
            return false;
        }
        $sameHost = strtolower($p['host']) === $host;
        $samePort = ($p['port'] ?? null) === $port || ($port === null && !isset($p['port']))
            || (in_array($p['scheme'] ?? '', ['http', 'https'], true) && ($p['port'] ?? null) === null && $port === null);
        return $sameHost && ($samePort || $port === null);
    }
}
