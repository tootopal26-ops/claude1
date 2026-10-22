# Backups and restore

## What gets backed up, and why

`php artisan backup:run` (`app/Domain/Backup/RunBackupAction.php`) writes
two gzipped files into `config('minimax.backup_path')` (default
`storage/app/backups`, overridable via `BACKUP_PATH`):

- **`db-<timestamp>.sql.gz`** — a `mysqldump --single-transaction` of the
  configured database connection. This is the source of truth for
  everything money-related: wallets, ledger entries, withdrawal
  requests, tournament entries and results. `--single-transaction`
  gives a consistent snapshot without locking tables, so the dump never
  blocks the live app.
- **`storage-<timestamp>.tar.gz`** — everything under `storage/app`
  that isn't reconstructible from the database: uploaded tournament
  result evidence, withdrawal payout proof, internal staff documents
  (all tracked in the `storage_assets` table, but the table only has
  metadata — the files themselves live on disk), and the Firebase
  Admin SDK service account JSON.

What's deliberately **not** in either archive: the application code
(it's in git) and `.env` (it holds live secrets — back it up separately,
somewhere access-controlled, like a password manager; never inside the
same archive these files end up in, which may get copied around more
casually than a `.env`).

The command then deletes anything in `backup_path` older than
`config('minimax.backup_retention_days')` (default 14 days, via
`BACKUP_RETENTION_DAYS`).

It is scheduled nightly (`bootstrap/app.php`'s `withSchedule()`, 02:00
server time) once the server's cron is wired up per
[`deployment.md`](deployment.md).

## A local backup is not a backup plan

`backup_path` defaults to a path under `storage/app`, i.e. **on the same
disk, on the same server**, as everything it's protecting. If that
server's disk fails, or the VPS itself is lost, the backups are lost
with it. Point `BACKUP_PATH` at a separate mounted volume if the hosting
provider offers one, and — more importantly — copy the backup directory
off the server on a schedule, e.g. a nightly `rsync`/`scp` to a second
machine, or sync to S3-compatible object storage with `rclone` or the
AWS CLI once real credentials for one exist. This repo doesn't wire up
automatic off-server replication itself, because doing so convincingly
needs real credentials for wherever backups should land — a decision
for whoever operates the deployment, not one to fabricate here.

## Restoring

Have the operator confirm before running any of this against a live
server — a restore overwrites current data.

1. **Stop traffic to the app** (or at minimum stop the queue worker and
   disable the cron entry) so nothing writes to the database mid-restore.
   ```bash
   supervisorctl stop minimax-queue:*
   ```
2. **Restore the database.**
   ```bash
   gunzip -c db-<timestamp>.sql.gz | mysql -h 127.0.0.1 -u <user> -p <database>
   ```
3. **Restore `storage/app`.** Back up whatever's currently there first
   (in case the archive turns out to be the wrong one), then extract:
   ```bash
   mv storage/app storage/app.bak-$(date +%s)
   mkdir storage/app
   tar -xzf storage-<timestamp>.tar.gz -C storage/app
   chmod -R ug+w storage/app
   ```
4. **Reconcile wallets.** The restored ledger and the restored cached
   balance columns should already agree (they were dumped together,
   atomically), but this is the cheap, safe way to confirm it rather
   than assume it:
   ```bash
   php artisan wallet:reconcile
   ```
   A clean run ("All wallets reconcile cleanly against their ledgers")
   means the restore is internally consistent. Any drift reported here
   after a restore is worth investigating before resuming traffic — it
   means the database and storage archives weren't actually from the
   same point in time, or the dump was taken mid-write somehow despite
   `--single-transaction`.
5. **Resume.**
   ```bash
   php artisan queue:restart
   supervisorctl start minimax-queue:*
   ```
   Re-enable the cron entry if it was disabled in step 1.

## Testing a restore without touching production

`tests/Feature/Backup/RunBackupActionTest.php` proves the backup action
itself produces a valid, restorable dump (it gunzips the output and
asserts the seeded test row is actually in there) every time the test
suite runs — but that's a world away from having actually rehearsed a
restore against a real deployment. Periodically — quarterly is a
reasonable cadence — actually run the restore steps above against a
disposable staging copy of the server, not just trust that the backup
files exist. A backup that has never been restored is a hypothesis, not
a verified backup.
