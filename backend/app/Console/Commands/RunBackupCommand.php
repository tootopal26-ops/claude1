<?php

namespace App\Console\Commands;

use App\Domain\Backup\RunBackupAction;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('backup:run')]
#[Description('Write a gzipped database dump and storage/app archive, then prune backups past the retention window')]
class RunBackupCommand extends Command
{
    public function handle(RunBackupAction $backup): int
    {
        $result = $backup->execute();

        $this->info("Database dump: {$result['database']}");
        $this->info("Storage archive: {$result['storage']}");
        $this->line("{$result['pruned']} old backup file(s) pruned.");

        return self::SUCCESS;
    }
}
