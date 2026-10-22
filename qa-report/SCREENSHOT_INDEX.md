# Mini Max — Screenshot Index

All screenshots are full-page captures from a real Chromium browser
(Playwright) driving the actual running Laravel app (`php artisan
serve` + MySQL), unless noted otherwise. No Android screenshots exist —
see `BUG_REPORT.md`'s ENV-001 for why.

## Admin panel (`qa-report/screenshots/admin/`)

All captured as the seeded super-admin (`admin`) unless noted.

| # | File | Screen | Notes |
|---|---|---|---|
| 1 | `01-tournaments-list.png` | Tournaments list | Seeded data only |
| 2 | `02-tournaments-create.png` | Tournament create form | Empty |
| 3 | `03-users-list.png` | Players list | Empty state |
| 4 | `04-deposits-list.png` | Deposits list | Empty state |
| 5 | `05-withdrawals-list.png` | Withdrawals list | Empty state |
| 6 | `06-notifications-list.png` | Notifications list | Empty state |
| 7 | `07-notifications-create.png` | Send notification form | Empty |
| 8 | `08-staff-list.png` | Staff accounts list | Seeded admin only |
| 9 | `09-staff-create.png` | New staff account form | Empty |
| 10 | `10-presets-list.png` | Permission presets list | Seeded presets |
| 11 | `11-presets-create.png` | New preset form (full permission grid) | Empty |
| 12 | `12-audit-logs.png` | Audit log | Empty state |
| 13 | `13-risk-alerts.png` | Risk alerts | Empty state |
| 14 | `14-leaderboards.png` | Leaderboards (daily tab) | Empty period |
| 15 | `15-salary-rules-list.png` | Staff salary rules | Empty state |
| 16 | `16-salary-rules-create.png` | New salary rule form | Empty |
| 17 | `17-salary-settlements-list.png` | Salary settlements | Empty state |
| 18 | `18-storage-dashboard.png` | Storage dashboard | All buckets 0% |
| 19 | `19-templates-list.png` | Recurring templates list | Empty state |
| 20 | `20-templates-create.png` | New template form | Empty |
| 21 | `21-legal-list.png` | Legal documents list | Empty state |
| 22 | `22-legal-create.png` | Publish legal document form | Empty |
| 23 | `23-settings.png` | Platform settings form | Defaults |
| 24 | `24-payment-settings.png` | Payment provider settings | Alpha UPI config |
| 25 | `25-tournament-results-empty.png` | Results entry form | Before submit |
| 26 | `26-tournament-results-published.png` | Results entry form | Success banner, status flipped to completed |
| 27 | `27-withdrawals-populated.png` | Withdrawals list | 1 pending row |
| 28 | `28-withdrawal-detail-pending.png` | Withdrawal detail | Pending — Approve/Reject |
| 29 | `29-withdrawal-approved.png` | Withdrawal detail | Approved — Mark processing |
| 30 | `30-withdrawal-processing.png` | Withdrawal detail | Processing — Mark paid/failed forms |
| 31 | `31-withdrawal-paid.png` | Withdrawal detail | Paid, settlement reference shown |
| 32 | `32-users-populated.png` | Players list | 2 real players |
| 33 | `33-user-detail.png` | Player detail | Full wallet ledger + tournament entries history |
| 34 | `34-deposits-populated.png` | Deposits list | success/failed/pending rows |
| 35 | `35-tournaments-populated.png` | Tournaments list | upcoming/cancelled/completed rows |
| 36 | `36-tournament-entries.png` | Tournament entries + room-credentials form | 1 entry |
| 37 | `37-audit-logs-populated.png` | Audit log | Full real trail (player/staff/system actors) |
| 38 | `38-presets-after-create.png` | Presets list | Includes new "QA Tester Preset" |
| 39 | `39-staff-create-validation-error.png` | New staff form | Password-confirmation mismatch error |
| 40 | `40-staff-list-populated.png` | Staff accounts list | Includes new "QA Staffer" |
| 41 | `41-staff-edit.png` | Edit staff account | Pre-populated |
| 42 | `42-preset-edit.png` | Edit preset | Pre-populated, checkboxes reflect saved permissions |
| 43 | `43-wallet-adjustment-applied.png` | Player detail, after "Adjust wallet" | Confirms adjustment form works; this capture is also BUG-004's "before" evidence (columns jammed) |

## Staff panel (`qa-report/screenshots/staff/`)

See `TEST_RESULTS.md` §2 for the full narrative — captures the same
`/admin/*` routes under staff accounts with restricted permission
sets, to verify role-based nav hiding, the post-login landing page,
and 403 enforcement.

| # | File | Screen | Notes |
|---|---|---|---|
| 1 | `01-no-permissions-landing.png` | `/admin/home` | Zero-permission account — friendly "no access" message (BUG-003 after-fix evidence, worst case) |
| 2 | `02-limited-permissions-home.png` | `/admin/home` | Legal+Notifications-only account — home shows exactly those 2 section cards |
| 3 | `03-withdrawals-403.png` | `/admin/withdrawals` | Same limited account, direct navigation to a restricted route — still correctly 403s |
| 4 | `04-legal-permitted-access.png` | `/admin/legal` | Same account — permitted route loads fine |
| 5 | `05-notifications-permitted-access.png` | `/admin/notifications` | Same account — permitted route loads fine |

## End-to-end business-rule verification (`qa-report/screenshots/e2e/`)

Real state-machine transitions driven through the live Admin UI
(Playwright), each screenshotted at every step. See `TEST_RESULTS.md`
§4 for the full narrative and wallet-ledger proof that each transition
moved the correct amount exactly once.

| File | Flow | Notes |
|---|---|---|
| `withdrawal-reject-01-pending.png` | Withdrawal reject path | Pending — Approve/Reject form |
| `withdrawal-reject-02-rejected.png` | Withdrawal reject path | After reject — reason shown, amount released |
| `withdrawal-fail-01-pending.png` | Withdrawal markFailed path | Pending |
| `withdrawal-fail-02-approved.png` | Withdrawal markFailed path | After approve |
| `withdrawal-fail-03-processing.png` | Withdrawal markFailed path | After "Mark as processing" |
| `withdrawal-fail-04-failed.png` | Withdrawal markFailed path | After "Mark as failed" — reason shown, amount released |
| `result-revision-01-before.png` | Result revision | Original published result (rank 1, 7 kills, 50.00 prize) |
| `result-revision-02-after.png` | Result revision | Revised (9 kills, 80.00 prize) — "Results revised." banner, revision_number incremented |

## Bug evidence (`qa-report/screenshots/bugs/`)

| File | Bug | What it shows |
|---|---|---|
| `bug-001-before.png` | BUG-001 | Zoomed crop: "Admin" text overlapping the "Tournaments" nav icon |
| `bug-001-after.png` | BUG-001 | Same nav, after the fix — correct spacing |
| `bug-002-before-raw-trace.txt` | BUG-002 | Raw 500 response with full stack trace (captured live) |
| `bug-002-after-clean-error.txt` | BUG-002 | Same request after the fix — clean 422 |
| `bug-003-after-home-page.png` | BUG-003 | Fixed `/admin/home` landing page, zero-permission account |
| `bug-004-before.png` | BUG-004 | Zoomed crop: "Amount Reason" columns jammed with zero gap |
| `bug-004-after.png` | BUG-004 | Same table, after the fix — clean gaps between all columns |

## Mobile app

None — see `BUG_REPORT.md`'s ENV-001. No real Android emulator/device
was reachable in this sandbox, and no screenshot was fabricated to
stand in for one.
