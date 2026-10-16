# Operations: scheduled jobs and cron

Everything that happens in the background is a **named job** run by PHP's command line. There is no always-running worker, no queue server and no
Redis. On cPanel that means **Cron Jobs**; on a host without cron there is an HTTP trigger (see the bottom of this page).

## The jobs

| Job | What it does | Runs | Suggested cPanel schedule | Dry run |
|---|---|---|---|---|
| `payments` | expires unpaid sessions, re-checks pending and recently expired payments with the gateway, runs the payment-checks scan | every 5 min | `*/5 * * * *` | yes |
| `recovery` | submits paid AUTO orders that are waiting, retries safe failures with back-off, follows provider orders, hands stuck orders to a person | every 5 min | `*/5 * * * *` | yes |
| `analytics` | recomputes the dashboard rollups of the last days; credits verified orders to a campaign when that was missed | every 5 min | `*/5 * * * *` | – |
| `watchdog` | runs the Business Watchdog's 12 health checks and raises, updates or resolves alerts ([WATCHDOG-AND-REPORTS.md](WATCHDOG-AND-REPORTS.md)) | every 15 min | `*/15 * * * *` | yes |
| `report` | writes the daily report of every finished business day (Asia/Kolkata) that has none yet | once a day after 00:10 IST | `10 * * * *` (see below) | yes |
| `cleanup` | deletes what has a lifetime: rate limits, idempotency keys, expired sessions, visitor sessions over 400 days, webhook deliveries over 180 days, job history over 90 days, closed payment flags over a year, AI usage log over 180 days, support conversations past their retention, old pricing assignments, log files over 30 days | hourly | `17 * * * *` | – (a dry run deletes nothing) |

`php bin/cron.php list` prints every job, its state, its suggested schedule and its last run.

### Which cron lines to add

**One line is enough** — it runs the three quick jobs every time and every other job when it is due:

```
* * * * * /usr/local/bin/php /home/USER/smb/bin/cron.php >/dev/null 2>&1
```

**Or one line per job** (each has its own lock, time budget and run history; use this if your host limits "every minute" jobs):

```
*/5  * * * *  /usr/local/bin/php /home/USER/smb/bin/cron.php payments  >/dev/null 2>&1
*/5  * * * *  /usr/local/bin/php /home/USER/smb/bin/cron.php recovery  >/dev/null 2>&1
*/5  * * * *  /usr/local/bin/php /home/USER/smb/bin/cron.php analytics >/dev/null 2>&1
*/15 * * * *  /usr/local/bin/php /home/USER/smb/bin/cron.php watchdog  >/dev/null 2>&1
10   * * * *  /usr/local/bin/php /home/USER/smb/bin/cron.php report    >/dev/null 2>&1
17   * * * *  /usr/local/bin/php /home/USER/smb/bin/cron.php cleanup   >/dev/null 2>&1
```

`/usr/local/bin/php` is the usual path on cPanel; **Admin → System** shows the exact command for your installation, ready to copy. Use the PHP CLI
that matches the PHP version selected for the site (cPanel → Select PHP Version; the CLI binary is often `/opt/cpanel/ea-php82/root/usr/bin/php`).

### The daily report and the time zone

Reports cover **Asia/Kolkata** days (midnight to midnight IST; India has no daylight saving). cPanel runs cron in the **server's** time zone, which is
often UTC — so the schedule above is deliberately **not** tied to a clock time: the `report` job is idempotent and catches up (it writes every
finished day without a report, at most the last 7), so running it every hour at minute 10 simply means the new day's report appears within the hour
after midnight IST. If you use the one-line cron instead, `report` is started exactly once per IST day, after 00:10 IST, and a failed attempt is
retried no sooner than 15 minutes later.

## What every run guarantees

* **A lock** (a MySQL advisory lock per job): two cron processes, or a cron line and an admin pressing "Run now", can never work on the same job at once.
  The second one ends immediately as `skipped`.
* **A time budget** (120–240 s depending on the job): the job checks it between batches and stops cleanly, recording `timeout`; the rest is picked up by
  the next run. The PHP time limit is raised only to that budget plus a margin.
* **A record**: start, finish, duration, a small result (counters), who started it (`cron`, `admin`, `web`), whether it was a dry run, and — if it
  failed — an **error category** (`database`, `network`, `timeout`, `logic`) and a sanitised message (no path, no SQL, no key). A run whose process was
  killed is marked `abandoned` by the next run, so the history never claims a run that did not finish.
* **Safe repeats**: every job can be run twice in a row without harm. Nothing that spends money or talks to a provider is retried blindly — see
  [ORDER-RECOVERY.md](ORDER-RECOVERY.md).
* **An exit code**: 0 when the run finished (or was skipped because the same job was already running), 1 when it failed, 2 for an unknown job — so a cron
  monitor can alert on it.

Flags: `php bin/cron.php <job> --dry-run` (for the jobs marked above: what it would do, nothing is changed), `-v` prints the result.

## Where to see the health of the jobs

* **Admin → System → Scheduled jobs** (permission `jobs.view`): every job with its schedule, its **state** — *on schedule*, *running now*, *overdue*
  (no successful run for much longer than its schedule), *failing* (its last run failed) or *never run* — its last run (when, how long, who started it,
  the error category and message), its last success and its failures in the last 24 hours. Owners and managers (`jobs.run`) can **Run now** or do a
  **Dry run**; a manual run is audited and limited to 10 per 10 minutes per admin.
* **Admin → Business alerts** raises an alert when a job keeps failing or has stopped running (the *Scheduled jobs* check). If the cron line itself is
  not running, nothing can raise that alert — which is why the dashboard also shows a banner when no cron run was seen for 15 minutes.

## Without cron

* **An HTTP trigger**: set `CRON_KEY` in `.env`, then have an external pinger (cron-job.org, UptimeRobot) call `https://your-domain.com/cron/run?key=<CRON_KEY>`
  every minute. It runs exactly the same jobs.
* **Visitor traffic** (last resort): the site starts the same run from a visitor's request at most every 3 minutes. Small shops work this way, but with
  no visitors nothing expires or retries — a real cron job is more reliable.

The cron scripts are not reachable from the web: `bin/` is outside the web root (`public/`), and the HTTP trigger refuses a missing or wrong key.

## Retention (what is deleted and when)

Only the `cleanup` job deletes, and only rows that have a lifetime. Business records — orders, payments, order costs, expenses, ad spend,
**daily reports**, **business alerts**, audit logs — are never deleted by it. Daily reports are small (a few kilobytes each) and are kept indefinitely.
