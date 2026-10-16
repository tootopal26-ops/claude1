#!/usr/bin/env bash
# Runs EVERY QA suite in sequence and prints a one-line result for each (local dev only; resets the dev database several times).
#   bash tools/qa/run-all.sh            everything except the 5½-minute real-time countdown test
#   bash tools/qa/run-all.sh --long     …including it
#   bash tools/qa/run-all.sh site-      only suites whose name contains "site-"
set -u
cd "$(dirname "$0")/../.."
LONG=0; FILTER=""
for a in "$@"; do case "$a" in --long) LONG=1;; *) FILTER="$a";; esac; done
bash tools/qa/dev-up.sh || exit 1
mkdir -p /tmp/qa
SUITES=(
  "php tests/run.php"
  "node tools/qa/targets-parity.mjs"
  "node tools/qa/admin-auth.mjs"
  "node tools/qa/admin-dashboard.mjs"
  "node tools/qa/admin-orders.mjs"
  "node tools/qa/admin-catalog.mjs"
  "node tools/qa/admin-tags.mjs"
  "node tools/qa/admin-integrations.mjs"
  "node tools/qa/admin-checks.mjs"
  "node tools/qa/admin-recovery.mjs"
  "node tools/qa/admin-finance.mjs"
  "node tools/qa/pay-alpha-api.mjs"
  "node tools/qa/admin-mobile.mjs"
  "node tools/qa/install-e2e.mjs"
  "node tools/qa/site-flows.mjs"
  "node tools/qa/site-layout.mjs"
  "node tools/qa/site-input.mjs"
  "node tools/qa/site-badges.mjs"
  "node tools/qa/site-failures.mjs"
  "node tools/qa/site-tracking.mjs"
  "node tools/qa/security-audit.mjs"
  "node tools/qa/a11y.mjs"
  "node tools/qa/perf.mjs"
  "node tools/qa/apache-check.mjs"
)
[ "$LONG" = 1 ] && SUITES+=("node tools/qa/pay-real-expiry.mjs")
FAILED=0; T0=$(date +%s)
printf "\n%-44s %-9s %s\n" "SUITE" "RESULT" "TIME"
for cmd in "${SUITES[@]}"; do
  [ -n "$FILTER" ] && [[ "$cmd" != *"$FILTER"* ]] && continue
  name=$(echo "$cmd" | sed 's#^php tests/run.php#php-tests#; s#.*/##; s#\.mjs##')
  s=$(date +%s)
  if $cmd >"/tmp/qa/run-$name.log" 2>&1; then r="PASS"; else r="FAIL"; FAILED=$((FAILED+1)); fi
  summary=$(sed 's/\x1b\[[0-9;]*m//g' "/tmp/qa/run-$name.log" | grep -E "passed|failed" | tail -1)
  printf "%-44s %-9s %4ss   %s\n" "$name" "$r" "$(( $(date +%s) - s ))" "$summary"
done
printf "\nTOTAL %ss — %s\n" "$(( $(date +%s) - T0 ))" "$([ $FAILED = 0 ] && echo 'ALL SUITES PASSED' || echo "$FAILED SUITE(S) FAILED — see /tmp/qa/run-<suite>.log")"
exit $FAILED
