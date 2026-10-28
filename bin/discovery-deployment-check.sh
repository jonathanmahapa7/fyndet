#!/usr/bin/env bash
set -Eeuo pipefail
APP=/home/bathokwe/fyndet-app
PHP=/opt/cpanel/ea-php83/root/usr/bin/php
printf 'FyndEt deployment prerequisites\n'
test -d "$APP" && test -x "$PHP"
"$PHP" -l "$APP/app/footprint_engine.php"
"$PHP" -l "$APP/bin/process-investigations.php"
echo 'PASS: repository preflight; no application changes.'
