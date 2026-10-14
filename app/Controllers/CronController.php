<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Env;
use App\Core\HttpException;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Services\Cron;

/** GET /cron/run?key=CRON_KEY — for hosts that can only schedule a URL (e.g. cron-job.org). */
final class CronController
{
    public function run(Request $req, array $p): Response
    {
        RateLimiter::enforce('cron-http', $req->ip(), 20, 60);
        $expected = (string) Env::get('CRON_KEY', '');
        $given = (string) ($req->header('x-cron-key') ?? $req->queryString('key', '', 200));
        if ($expected === '' || !hash_equals($expected, $given)) {
            throw new HttpException(403, 'Forbidden.');
        }
        return Response::json(['ok' => true, 'result' => Cron::run()]);
    }
}
