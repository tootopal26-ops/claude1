<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\HttpException;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Services\Audit;
use App\Services\Jobs;

/** Scheduled jobs: their health (last run, last success, failures) and "run now". */
final class JobsController extends BaseController
{
    /** GET /admin/api/jobs */
    public function list(Request $req, array $p): Response
    {
        $this->authorize($req, 'jobs.view');
        return $this->ok(['jobs' => Jobs::overview()]);
    }

    /** POST /admin/api/jobs/{job}/run  { dry_run? } — the same locked, time-bounded, recorded run as the cron line; 10 per 10 minutes per admin. */
    public function run(Request $req, array $p): Response
    {
        $admin = $this->authorize($req, 'jobs.run');
        $job = (string) ($p['job'] ?? '');
        if (!Jobs::exists($job)) {
            throw new HttpException(404, 'Unknown job.');
        }
        RateLimiter::enforce('job-run', (string) $admin['id'], 10, 600, 'Too many manual runs. Wait a few minutes.');
        $dry = ($req->json()['dry_run'] ?? false) === true;
        $run = Jobs::run($job, ['source' => 'admin', 'dry_run' => $dry]);
        Audit::log('job.run', 'job', $job, ['status' => $run['status'], 'dry_run' => $dry], $req, $admin);
        return $this->ok(['run' => $run, 'jobs' => Jobs::overview()]);
    }
}
