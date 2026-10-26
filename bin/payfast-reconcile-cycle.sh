#!/usr/bin/env bash
# FyndEt — non-mutating PayFast recovery cycle (except reconciling confirmed local state).
set -Eeuo pipefail
umask 077
ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
PHP="${FYNDET_PHP_BIN:-/opt/cpanel/ea-php83/root/usr/bin/php}"
LOCK="$ROOT/var/.payfast-recovery.lock"
LOG="$ROOT/var/payfast-recovery-status.log"
[[ -x "$PHP" && -f "$ROOT/.env" && -f "$ROOT/bin/payfast-update-reconcile.php" ]] || { echo "FAIL: Recovery preflight" >&2; exit 1; }
[[ -d "$ROOT/var" && -w "$ROOT/var" ]] || { echo "FAIL: Private state directory" >&2; exit 1; }
exec 9>"$LOCK"
if ! flock -n 9; then
  echo "INFO: Previous PayFast recovery cycle is still running"
  exit 0
fi
TMP="$(mktemp "$ROOT/var/.payfast-status-XXXXXXXX")"
trap 'rm -f "$TMP"' EXIT
{
  echo "FYNDET PAYFAST SANDBOX RECOVERY — $(date -u +%Y-%m-%dT%H:%M:%SZ)"
  echo "Mode: signed provider fetch only; never PATCH, cancel, or charge"
  "$PHP" "$ROOT/bin/payfast-update-reconcile.php"
  "$PHP" "$ROOT/bin/payfast-plan-change-audit.php"
  "$PHP" "$ROOT/bin/payfast-failure-recovery-audit.php"
} > "$TMP" 2>&1 || {
  cat "$TMP" > "$LOG"
  chmod 600 "$LOG"
  echo "FAIL: PayFast reconciliation needs review; see private status log" >&2
  exit 1
}
cat "$TMP" > "$LOG"
chmod 600 "$LOG"
echo "PASS: PayFast recovery and billing review checks"
