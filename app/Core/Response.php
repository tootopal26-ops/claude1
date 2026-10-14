<?php

declare(strict_types=1);

namespace App\Core;

/**
 * HTTP response value object. Build it anywhere, the Kernel sends it (after
 * applying the global security headers).
 */
final class Response
{
    /** @var array<string,string> */
    public array $headers = [];
    /** @var list<array{0:string,1:string,2:array<string,mixed>}> */
    public array $cookies = [];
    /** @var list<callable> work to run AFTER the response is flushed to the client */
    public array $after = [];
    public ?string $filePath = null;

    public function __construct(public int $status = 200, public string $body = '', array $headers = [])
    {
        $this->headers = $headers;
    }

    public static function html(string $html, int $status = 200): self
    {
        return new self($status, $html, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /** @param array<mixed>|object $data */
    public static function json(mixed $data, int $status = 200, array $headers = []): self
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        return new self($status, $json === false ? '{}' : $json, ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store'] + $headers);
    }

    public static function text(string $text, int $status = 200, string $type = 'text/plain'): self
    {
        return new self($status, $text, ['Content-Type' => $type . '; charset=utf-8']);
    }

    public static function redirect(string $location, int $status = 302): self
    {
        return new self($status, '', ['Location' => $location, 'Cache-Control' => 'no-store']);
    }

    public static function empty(int $status = 204): self
    {
        return new self($status, '');
    }

    public static function binary(string $bytes, string $mime, array $headers = []): self
    {
        return new self(200, $bytes, ['Content-Type' => $mime] + $headers);
    }

    public function header(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    /** @param array<string,mixed> $options expires|path|secure|httponly|samesite */
    public function cookie(string $name, string $value, array $options = []): self
    {
        $this->cookies[] = [$name, $value, $options];
        return $this;
    }

    public function afterSend(callable $fn): self
    {
        $this->after[] = $fn;
        return $this;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value, true);
            }
            foreach ($this->cookies as [$name, $value, $options]) {
                setcookie($name, $value, $options);
            }
        }
        if ($this->status !== 204 && $this->status !== 304) {
            echo $this->body;
        }
    }

    /** Flush to the client, then keep running (webhook → SMM submission, etc). */
    public function sendAndContinue(): void
    {
        $this->send();
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        } else {
            while (ob_get_level() > 0) {
                @ob_end_flush();
            }
            flush();
        }
        ignore_user_abort(true);
        @set_time_limit(90);
        foreach ($this->after as $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                Log::exception($e, 'Post-response task failed');
            }
        }
    }
}
