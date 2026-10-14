<?php
declare(strict_types=1);

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;

function rt_router(): Router
{
    $r = new Router();
    $r->get('/', fn() => Response::text('home'));
    $r->get('/pay/{ref:[a-f0-9]{32}}', fn(Request $q, array $p) => Response::text('pay:' . $p['ref']));
    $r->get('/pay/{ref:[a-f0-9]{32}}/qr.png', fn(Request $q, array $p) => Response::text('qr:' . $p['ref']));
    $r->get('/orders/{id:\d+}', fn(Request $q, array $p) => Response::text('order:' . $p['id']));
    $r->post('/orders/{id:\d+}/action', fn(Request $q, array $p) => Response::text('act:' . $p['id']));
    $r->get('/files/{name}', fn(Request $q, array $p) => Response::text('file:' . $p['name']));
    return $r;
}

T::test('router: placeholders with braces inside the regex ({32}) match', function () {
    $r = rt_router();
    $ref = str_repeat('ab12', 8);
    T::same("pay:$ref", $r->dispatch(Request::fake('GET', "/pay/$ref"))->body);
    T::same("qr:$ref", $r->dispatch(Request::fake('GET', "/pay/$ref/qr.png"))->body);
    T::same('order:42', $r->dispatch(Request::fake('GET', '/orders/42'))->body);
    T::same('file:a.txt', $r->dispatch(Request::fake('GET', '/files/a.txt'))->body);
});
T::test('router: constrained placeholders reject bad values (404) and wrong methods (405)', function () {
    $r = rt_router();
    foreach (['/pay/short', '/pay/' . str_repeat('G', 32), '/pay/' . str_repeat('a', 33), '/orders/abc', '/orders/-1', '/nope', '/pay/' . str_repeat('a', 32) . '/extra'] as $path) {
        T::throws(HttpException::class, fn() => $r->dispatch(Request::fake('GET', $path)), 404);
    }
    $e = T::throws(HttpException::class, fn() => $r->dispatch(Request::fake('GET', '/orders/5/action')), 405);
    T::same('POST', $e->headers['Allow']);
    T::same('act:5', $r->dispatch(Request::fake('POST', '/orders/5/action'))->body);
});
T::test('router: literal regex characters in a pattern are escaped', function () {
    $r = new Router();
    $r->get('/a.b/(x)', fn() => Response::text('ok'));
    T::same('ok', $r->dispatch(Request::fake('GET', '/a.b/(x)'))->body);
    T::throws(HttpException::class, fn() => $r->dispatch(Request::fake('GET', '/aXb/(x)')), 404);
    T::throws(InvalidArgumentException::class, function () { (new Router())->get('/x/{id:\d+', fn() => null); });
});
