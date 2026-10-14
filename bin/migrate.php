<?php
/** php bin/migrate.php — apply pending database migrations. */
declare(strict_types=1);
require __DIR__ . '/_cli.php';

use App\Support\Migrator;

$applied = Migrator::run();
echo $applied ? 'Applied: ' . implode(', ', $applied) . PHP_EOL : "Database is up to date." . PHP_EOL;
