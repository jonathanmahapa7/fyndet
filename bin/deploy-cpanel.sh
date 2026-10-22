#!/usr/bin/env bash
# FyndEt deployment helper: dedicated cPanel user and dedicated site only.
set -Eeuo pipefail
umask 077
ACTION="${1:---check}"
[[ "$ACTION" == "--check" || "$ACTION" == "--deploy" ]] || { echo "Usage: --check or --deploy"; exit 2; }
ROOT="$(cd "$(dirname "$0")/.." && pwd -P)"
[[ -f "$ROOT/public/index.php" && -f "$ROOT/.env" ]] || { echo "Private FyndEt installation missing"; exit 1; }
[[ "$(id -u)" != 0 ]] || { echo "Refusing root execution"; exit 1; }
[[ "$ROOT" != *"/public_html"* ]] || { echo "Private app must be outside public_html"; exit 1; }
PHP_BIN="$(command -v php)"
"$PHP_BIN" -l "$ROOT/public/index.php"
"$PHP_BIN" "$ROOT/bin/migrate-cpanel.php" --check
if [[ "$ACTION" == "--check" ]]; then
  "$PHP_BIN" "$ROOT/bin/preflight-cpanel.php" || true
  echo "Read-only check complete"
  exit 0
fi
[[ -n "${FYNDET_PUBLIC_ROOT:-}" ]] || { echo "Set FYNDET_PUBLIC_ROOT to the dedicated existing FyndEt domain document root"; exit 1; }
TARGET="$(cd "$FYNDET_PUBLIC_ROOT" && pwd -P)"
[[ "$TARGET" == "$HOME/"* && "$TARGET" != "$HOME" ]] || { echo "Unsafe target path"; exit 1; }
[[ -f "$TARGET/.fyndet-site-marker" ]] || { echo "Dedicated site marker missing; refusing to modify any site"; exit 1; }
[[ "$TARGET" != "$ROOT" && ! -L "$TARGET" ]] || { echo "Unsafe target"; exit 1; }
backup="$ROOT/var/deploy-backups/$(date -u +%Y%m%dT%H%M%SZ)"
mkdir -p "$backup" "$TARGET/assets"
for item in index.php router.php .htaccess assets; do
 if [[ -e "$TARGET/$item" ]]; then cp -a "$TARGET/$item" "$backup/"; fi
done
echo "Previous FyndEt public files backed up at $backup"
echo "Database backups and valid FYNDET_APP_ROOT setting must be verified before production use."
"$PHP_BIN" "$ROOT/bin/migrate-cpanel.php" --apply
for item in index.php router.php .htaccess; do cp -a "$ROOT/public/$item" "$TARGET/$item"; done
cp -a "$ROOT/public/assets/." "$TARGET/assets/"
"$PHP_BIN" "$ROOT/bin/preflight-cpanel.php"
echo "Code deployed to dedicated FyndEt document root. Run real HTTP, cron, SMTP and payment smoke tests."
