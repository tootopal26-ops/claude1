<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\Cron;
use App\Services\Tracking;

/** Anonymous visitor analytics beacons (see App\Services\Tracking for the privacy design). */
final class TrackController
{
    public function hit(Request $req, array $p): Response
    {
        $r = Tracking::hit($req, $req->json());
        $res = Response::json($r['body']);
        foreach ($r['cookies'] as [$name, $value, $options]) {
            $res->cookie($name, $value, $options);
        }
        // Hosts without cPanel cron still get their background jobs run, off the visitor's critical path.
        $res->afterSend(static fn() => Cron::maybeRun(180));
        return $res;
    }

    public function qualify(Request $req, array $p): Response
    {
        return Response::json(Tracking::qualify($req));
    }

    public function ping(Request $req, array $p): Response
    {
        Tracking::ping($req, $req->json());
        return Response::empty(204);
    }
}
