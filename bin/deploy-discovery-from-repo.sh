#!/usr/bin/env bash
set -Eeuo pipefail
APP=/home/bathokwe/fyndet-app
OWNER=bathokwe
EXPECTED=b16f30ec08d2f4467c188912325f907bf29ba5000343b2159633bdf9474bdc95
test "$(id -u)" -eq 0 || { echo "FAIL: root required"; exit 1; }
test -d "$APP/.git" || { echo "FAIL: FyndEt Git checkout missing"; exit 1; }
command -v sha256sum >/dev/null
STAGE="$(mktemp)"
trap 'rm -f "$STAGE"' EXIT
for n in 01 02 03 04 05 06 07; do
  runuser -u "$OWNER" -- git -C "$APP" show "origin/main:deploy/parts/discovery-bulk-$n.part" >> "$STAGE"
done
printf '%s  %s\n' "$EXPECTED" "$STAGE" | sha256sum --check --status || {
  echo "FAIL: Repository installer checksum mismatch; no deployment attempted."
  exit 1
}
bash -n "$STAGE"
echo "PASS: Repository installer SHA-256 and Bash syntax verified."
FYNDET_DEPLOY_APPROVED=1 bash "$STAGE"
