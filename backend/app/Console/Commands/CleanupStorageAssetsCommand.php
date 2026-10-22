<?php

namespace App\Console\Commands;

use App\Domain\Storage\CleanupStorageAssetsAction;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('storage:cleanup')]
#[Description('Delete storage assets past their bucket\'s retention window, unless retained or tied to an open risk alert')]
class CleanupStorageAssetsCommand extends Command
{
    public function handle(CleanupStorageAssetsAction $cleanup): int
    {
        $deleted = $cleanup->execute();

        $this->line("{$deleted} storage asset(s) deleted.");

        return self::SUCCESS;
    }
}
