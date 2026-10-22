<?php

namespace Tests\Feature\Backup;

use App\Domain\Backup\RunBackupAction;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * Like DepositServiceTest's duplicate-webhook race test, this
 * deliberately runs against a real MySQL database rather than the
 * sqlite in-memory connection the rest of the suite uses — mysqldump
 * only makes sense against a real server, and this proves the backup
 * action's own output is genuinely restorable, not just that the
 * `tar`/`gzip` calls didn't error.
 */
class RunBackupActionTest extends TestCase
{
    public function test_it_writes_a_restorable_database_dump_and_a_storage_archive(): void
    {
        if (! Process::run('mysql --version')->successful()) {
            $this->markTestSkipped('mysql client not available to run a real backup against.');
        }

        $originalDefault = config('database.default');
        $originalDatabaseName = config('database.connections.mysql.database');

        config(['database.connections.mysql.database' => 'minimax_testing']);
        DB::purge('mysql');
        Artisan::call('migrate:fresh', ['--database' => 'mysql', '--force' => true]);
        DB::connection('mysql')->table('games')->insert([
            'name' => 'Backup Smoke Test Game',
            'display_order' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Outside storage/app (unlike the real default backup_path) so the
        // storage archive step below can't try to archive itself.
        $backupPath = storage_path('framework/testing/backup-test-'.uniqid());
        config(['minimax.backup_path' => $backupPath, 'database.default' => 'mysql']);

        try {
            $result = app(RunBackupAction::class)->execute();

            $this->assertFileExists($result['database']);
            $this->assertFileExists($result['storage']);
            $this->assertGreaterThan(0, filesize($result['database']));
            $this->assertGreaterThan(0, filesize($result['storage']));

            $dumpContents = shell_exec('gunzip -c '.escapeshellarg($result['database']));
            $this->assertStringContainsString('Backup Smoke Test Game', $dumpContents);
            $this->assertStringContainsString('CREATE TABLE', $dumpContents);
        } finally {
            config([
                'database.default' => $originalDefault,
                'database.connections.mysql.database' => $originalDatabaseName,
            ]);
            DB::purge('mysql');
            File::deleteDirectory($backupPath);
        }
    }

    public function test_it_prunes_backups_past_the_retention_window(): void
    {
        if (! Process::run('mysql --version')->successful()) {
            $this->markTestSkipped('mysql client not available to run a real backup against.');
        }

        $originalDefault = config('database.default');
        $originalDatabaseName = config('database.connections.mysql.database');

        config(['database.connections.mysql.database' => 'minimax_testing']);
        DB::purge('mysql');
        Artisan::call('migrate:fresh', ['--database' => 'mysql', '--force' => true]);

        $backupPath = storage_path('framework/testing/backup-test-'.uniqid());
        File::ensureDirectoryExists($backupPath);
        $staleFile = "{$backupPath}/db-stale.sql.gz";
        File::put($staleFile, 'stale');
        touch($staleFile, now()->subDays(30)->getTimestamp());

        config([
            'minimax.backup_path' => $backupPath,
            'minimax.backup_retention_days' => 14,
            'database.default' => 'mysql',
        ]);

        try {
            $result = app(RunBackupAction::class)->execute();

            $this->assertSame(1, $result['pruned']);
            $this->assertFileDoesNotExist($staleFile);
        } finally {
            config([
                'database.default' => $originalDefault,
                'database.connections.mysql.database' => $originalDatabaseName,
            ]);
            DB::purge('mysql');
            File::deleteDirectory($backupPath);
        }
    }
}
