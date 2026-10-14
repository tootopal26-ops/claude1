<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Small regex router.  Patterns use {name} (one path segment) or {name:regex}.
 * Handlers are  [Controller::class, 'method']  or closures and receive
 * (Request $req, array $params): Response.  Middleware are callables
 * fn(Request $req, callable $next): Response.
 */
final class Router
{
    /** @var list<array{method:string,regex:string,handler:callable|array,middleware:list<callable>}> */
    private array $routes = [];

    /** @param list<callable> $middleware */
    public function add(string $method, string $pattern, callable|array $handler, array $middleware = []): void
    {
        $this->routes[] = [
            'method'     => strtoupper($method),
            'regex'      => '#^' . self::compile($pattern) . '$#',
            'handler'    => $handler,
            'middleware' => $middleware,
        ];
    }

    /**
     * Turn "/pay/{ref:[a-f0-9]{32}}/qr.png" into a regex. Placeholders are {name} (one path
     * segment) or {name:regex}; the regex may itself contain braces, so braces are matched
     * by depth rather than with a naive pattern.
     */
    public static function compile(string $pattern): string
    {
        $out = '';
        $len = strlen($pattern);
        for ($i = 0; $i < $len; $i++) {
            $c = $pattern[$i];
            if ($c !== '{') {
                $out .= preg_quote($c, '#');
                continue;
            }
            $depth = 1;
            $j = $i + 1;
            while ($j < $len && $depth > 0) {
                if ($pattern[$j] === '{') {
                    $depth++;
                } elseif ($pattern[$j] === '}') {
                    $depth--;
                }
                $j++;
            }
            if ($depth !== 0) {
                throw new \InvalidArgumentException("Unbalanced braces in route pattern: {$pattern}");
            }
            $inner = substr($pattern, $i + 1, $j - $i - 2);
            $colon = strpos($inner, ':');
            $name = $colon === false ? $inner : substr($inner, 0, $colon);
            $rx = $colon === false ? '[^/]+' : substr($inner, $colon + 1);
            if (!preg_match('/^\w+$/D', $name)) {
                throw new \InvalidArgumentException("Bad placeholder name in route pattern: {$pattern}");
            }
            $out .= '(?P<' . $name . '>' . $rx . ')';
            $i = $j - 1;
        }
        return $out;
    }

    public function get(string $p, callable|array $h, array $mw = []): void { $this->add('GET', $p, $h, $mw); }
    public function post(string $p, callable|array $h, array $mw = []): void { $this->add('POST', $p, $h, $mw); }
    public function put(string $p, callable|array $h, array $mw = []): void { $this->add('PUT', $p, $h, $mw); }
    public function patch(string $p, callable|array $h, array $mw = []): void { $this->add('PATCH', $p, $h, $mw); }
    public function delete(string $p, callable|array $h, array $mw = []): void { $this->add('DELETE', $p, $h, $mw); }

    public function dispatch(Request $req): Response
    {
        $allowed = [];
        $method = $req->method === 'HEAD' ? 'GET' : $req->method;
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $req->path, $m)) {
                continue;
            }
            if ($route['method'] !== $method) {
                $allowed[] = $route['method'];
                continue;
            }
            $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            $handler = $route['handler'];
            if (is_array($handler) && is_string($handler[0])) {
                $handler = [new $handler[0](), $handler[1]];
            }
            $core = static function (Request $r) use ($handler, $params): Response {
                $res = $handler($r, $params);
                if (!$res instanceof Response) {
                    throw new \LogicException('Route handlers must return a Response.');
                }
                return $res;
            };
            $pipeline = array_reduce(
                array_reverse($route['middleware']),
                static fn(callable $next, callable $mw): callable => static fn(Request $r): Response => $mw($r, $next),
                $core
            );
            return $pipeline($req);
        }
        if ($allowed) {
            throw new HttpException(405, '', 'method_not_allowed', [], ['Allow' => implode(', ', array_unique($allowed))]);
        }
        throw new HttpException(404, '', 'not_found');
    }
}
