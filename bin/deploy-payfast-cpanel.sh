#!/usr/bin/env bash
# FyndEt PayFast release; run as root on cPanel.
set -Eeuo pipefail
umask 077
OWNER=bathokwe
APP=/home/bathokwe/fyndet-app
WEB=/home/bathokwe/public_html/jonathanmahapa.co.za/fyndet
PHP=/opt/cpanel/ea-php83/root/usr/bin/php
BASE=https://jonathanmahapa.co.za/fyndet

test "$(id -u)" -eq 0
test -x "$PHP"
test -f "$APP/.env"
test -f "$WEB/.fyndet-site-marker"
test -f "$WEB/index.php"
test -f "$APP/public/assets/app.js"
test -f "$APP/app/payfast.php"
test -f "$APP/app/payfast_manage.php"
test -f "$APP/database/008_payfast_billing.sql"
test -z "$(runuser -u "$OWNER" -- git -C "$APP" status --porcelain --untracked-files=no)"

echo "===== VERIFY PHP ====="
for path in public/index.php app/payfast.php app/payfast_manage.php app/billing.php app/administration.php bin/preflight-cpanel.php bin/migrate-cpanel.php tests/payfast.php; do
 "$PHP" -l "$APP/$path"
done

echo "===== CONTRACT TESTS ====="
runuser -u "$OWNER" -- "$PHP" "$APP/tests/payfast.php"
runuser -u "$OWNER" -- "$PHP" "$APP/tests/discovery.php"

echo "===== PRIVATE BACKUP ====="
BACKUP="$APP/var/payfast-release-$(date -u +%Y%m%dT%H%M%SZ)"
install -d -m 700 -o "$OWNER" -g "$OWNER" "$BACKUP"
cp -a "$APP/.env" "$BACKUP/.env"
cp -a "$WEB/index.php" "$BACKUP/index.php"
if test -f "$WEB/assets/app.js"; then cp -a "$WEB/assets/app.js" "$BACKUP/app.js"; fi
echo "Backup: $BACKUP"

echo "===== APPLY ADDITIVE DATABASE MIGRATION ====="
runuser -u "$OWNER" -- "$PHP" "$APP/bin/migrate-cpanel.php" --apply

echo "===== PRODUCTION READINESS ====="
runuser -u "$OWNER" -- "$PHP" "$APP/bin/preflight-cpanel.php"

echo "===== ACTIVATE WEB ENTRY POINT ====="
install -m 644 -o "$OWNER" -g "$OWNER" "$APP/public/index.php" "$WEB/index.php"
install -d -m 755 -o "$OWNER" -g "$OWNER" "$WEB/assets"
install -m 644 -o "$OWNER" -g "$OWNER" "$APP/public/assets/app.js" "$WEB/assets/app.js"
"$PHP" -l "$WEB/index.php"
cmp "$APP/public/assets/app.js" "$WEB/assets/app.js"

echo "===== SMOKE ====="
FAIL=0
for ROUTE in / /login /pricing /sitemap.xml; do
 CODE="$(curl -sS -L --max-time 20 -o /dev/null -w '%{http_code}' "$BASE$ROUTE")"
 echo "$ROUTE: $CODE"
 test "$CODE" = 200 || FAIL=1
done
PARENT="$(curl -sS -L --max-time 20 -o /dev/null -w '%{http_code}' https://jonathanmahapa.co.za/)"
echo "Parent website: $PARENT"
test "$PARENT" = 200 || FAIL=1
PRIVATE="$(curl -sS -L --max-time 20 -o /dev/null -w '%{http_code}' "$BASE/.env")"
echo "Private config protection: HTTP $PRIVATE"
test "$PRIVATE" = 403 -o "$PRIVATE" = 404 || FAIL=1
NOTIFY="$(curl -sS --max-time 20 -o /dev/null -w '%{http_code}' "$BASE/payfast/notify")"
echo "PayFast ITN GET rejected: HTTP $NOTIFY"
test "$NOTIFY" = 405 || FAIL=1
ASSET_CODE="$(curl -sS --max-time 20 -o /dev/null -w '%{http_code}' "$BASE/assets/app.js")"
echo "PayFast handoff JS: HTTP $ASSET_CODE"
test "$ASSET_CODE" = 200 || FAIL=1
if test "$FAIL" != 0;then
 install -m 644 -o "$OWNER" -g "$OWNER" "$BACKUP/index.php" "$WEB/index.php"
 if test -f "$BACKUP/app.js"; then install -m 644 -o "$OWNER" -g "$OWNER" "$BACKUP/app.js" "$WEB/assets/app.js"; fi
 echo "FAILED: Previous public entry point and available handoff JS backup restored; additive PayFast tables remain."
 exit 1
fi

echo "===== GATEWAY CONFIGURATION STATE ====="
# Print setting names/status only, never secret values.
runuser -u "$OWNER" -- "$PHP" -r '
$f="/home/bathokwe/fyndet-app/.env";$e=[];
foreach(file($f) as $line){$line=trim($line);if(!$line||$line[0]==="#"||!str_contains($line,"="))continue;[$k,$v]=explode("=",$line,2);$e[trim($k)]=trim($v," \t\n\r\0\x0B\"\x27");}
echo "Enabled: ".($e["PAYFAST_ENABLED"]??"0")."\n";
echo "Mode: ".($e["PAYFAST_MODE"]??"sandbox")."\n";
foreach(["PAYFAST_MERCHANT_ID","PAYFAST_MERCHANT_KEY","PAYFAST_PASSPHRASE","PAYFAST_PRICE_STARTER_ZAR","PAYFAST_PRICE_PRO_ZAR","PAYFAST_PRICE_BUSINESS_ZAR"] as $k)echo $k.": ".(!empty($e[$k])?"configured":"missing")."\n";
'
echo "PASS: PayFast release deployed. No credentials, subscriptions or billing flags were changed."
