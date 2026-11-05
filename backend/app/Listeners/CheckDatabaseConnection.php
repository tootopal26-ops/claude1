<?php

namespace App\Listeners;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Extends Laravel's built-in /up health check (registered in bootstrap/app.php via
 * `health: '/up'`) to also confirm the database is reachable. By itself, DiagnosingHealth
 * only proves the app booted — a deploy with a wrong DB_* value or a dead MySQL server
 * would still report healthy without this, which is exactly the situation a monitoring
 * check against /up exists to catch.
 */
class CheckDatabaseConnection
{
    public function handle(): void
    {
        try {
            DB::connection()->getPdo();
        } catch (Throwable $e) {
            throw new RuntimeException('Database connection failed.', previous: $e);
        }
    }
}
