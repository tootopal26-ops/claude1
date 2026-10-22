<?php

namespace App\Domain\Backup;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Writes a gzipped mysqldump of the configured database connection and a
 * gzipped tar of storage/app (uploaded evidence/documents, the Firebase
 * credentials file — everything that isn't reconstructible from the
 * database or from git) into config('minimax.backup_path'), then prunes
 * anything older than config('minimax.backup_retention_days').
 *
 * Deliberately shells out to mysqldump/tar/gzip rather than adding a
 * backup package — see docs/backup-restore.md for the restore
 * procedure and why a locally-written backup is not itself a disaster
 * recovery plan (it must be copied off the server it protects).
 */
class RunBackupAction
{
    /**
     * @return array{database: string, storage: string, pruned: int}
     */
    public function execute(): array
    {
        $connection = (string) config('database.default');
        $databaseConfig = config("database.connections.{$connection}");

        if (($databaseConfig['driver'] ?? null) !== 'mysql') {
            throw new RuntimeException("backup:run requires a mysql database connection; the active connection (\"{$connection}\") uses driver \"{$databaseConfig['driver']}\".");
        }

        $backupPath = (string) config('minimax.backup_path');
        File::ensureDirectoryExists($backupPath);

        $timestamp = now()->format('Y-m-d_His');

        $databaseArchive = $this->dumpDatabase($databaseConfig, $backupPath, $timestamp);
        $storageArchive = $this->archiveStorage($backupPath, $timestamp);
        $pruned = $this->pruneOldBackups($backupPath);

        return [
            'database' => $databaseArchive,
            'storage' => $storageArchive,
            'pruned' => $pruned,
        ];
    }

    private function dumpDatabase(array $config, string $backupPath, string $timestamp): string
    {
        $destination = "{$backupPath}/db-{$timestamp}.sql.gz";

        $dumpCommand = sprintf(
            'mysqldump --single-transaction --quick --host=%s --port=%s --user=%s %s | gzip > %s',
            escapeshellarg($config['host']),
            escapeshellarg((string) $config['port']),
            escapeshellarg($config['username']),
            escapeshellarg($config['database']),
            escapeshellarg($destination),
        );

        $result = Process::env(['MYSQL_PWD' => $config['password']])
            ->timeout(600)
            ->run($dumpCommand);

        if (! $result->successful()) {
            throw new RuntimeException('mysqldump failed: '.$result->errorOutput());
        }

        return $destination;
    }

    private function archiveStorage(string $backupPath, string $timestamp): string
    {
        $destination = "{$backupPath}/storage-{$timestamp}.tar.gz";

        // --exclude is relative to the -C directory; this keeps a
        // backups/ dir that happens to live under storage/app (the
        // default backup_path) from archiving itself.
        $result = Process::timeout(600)->run(sprintf(
            'tar --exclude=./backups -czf %s -C %s .',
            escapeshellarg($destination),
            escapeshellarg(storage_path('app')),
        ));

        if (! $result->successful()) {
            throw new RuntimeException('tar failed: '.$result->errorOutput());
        }

        return $destination;
    }

    private function pruneOldBackups(string $backupPath): int
    {
        $retentionDays = (int) config('minimax.backup_retention_days');
        $cutoff = now()->subDays($retentionDays)->getTimestamp();
        $pruned = 0;

        foreach (File::files($backupPath) as $file) {
            if ($file->getMTime() < $cutoff) {
                File::delete($file->getPathname());
                $pruned++;
            }
        }

        return $pruned;
    }
}
