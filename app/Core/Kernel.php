<?php

declare(strict_types=1);

namespace App\Core;

use App\Support\Installer;

/**
 * HTTP entry point: capture request → route → apply security headers → send.
 */
final class Kernel
{
    public static function run(): void
    {
        $req = Request::capture();
        $res = self::handle($req);
        SecurityHeaders::apply($res, $req);

        if ($req->method === 'HEAD') {
            $res->body = '';
        }
        if ($res->after) {
            $res->sendAndContinue();
        } else {
            $res->send();
        }
    }

    public static function handle(Request $req): Response
    {
        try {
            if (!str_starts_with($req->path, '/install') && !Installer::isInstalled()) {
                return Response::redirect(url('/install'));
            }
            $router = new Router();
            (require SMB_ROOT . '/app/routes.php')($router);
            return $router->dispatch($req);
        } catch (HttpException $e) {
            return ErrorPages::fromHttp($e, $req);
        } catch (\Throwable $e) {
            return ErrorPages::fromThrowable($e, $req);
        }
    }
}
