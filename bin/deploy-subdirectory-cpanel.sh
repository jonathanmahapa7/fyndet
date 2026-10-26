#!/usr/bin/env bash
# FyndEt ONLY: install at https://www.jonathanmahapa.co.za/fyndet
set -Eeuo pipefail
umask 077
[[ "$(id -un)" == "bathokwe" ]] || { echo "Run as bathokwe, not root"; exit 1; }
APP=/home/bathokwe/fyndet-app
WEB=/home/bathokwe/public_html/jonathanmahapa.co.za/fyndet
MODE="$1"
[[ "$MODE" == "--check" || "$MODE" == "--install" ]] || { echo 'Usage: --check or --install'; exit 2; }
[[ -f "$APP/public/index.php" && -f "$APP/public/mount.php" ]] || { echo 'Missing checkout in private application directory'; exit 1; }
[[ -d "$(dirname "$WEB")" ]] || { echo 'Expected website document root not present: verify path'; exit 1; }
[[ ! -L "$WEB" && ! -L "$APP" ]] || { echo 'Refusing symlink target'; exit 1; }
PHP=/opt/cpanel/ea-php82/root/usr/bin/php
[[ -x "$PHP" ]] || PHP="$(command -v php)"
"$PHP" -l "$APP/public/index.php"
"$PHP" -l "$APP/public/mount.php"
echo "Public target: $WEB"
echo 'Expected URL: https://www.jonathanmahapa.co.za/fyndet'
if [[ "$MODE" == "--check" ]]; then
 echo 'Dry-run completed; no files modified.'
 exit 0
fi
[[ -f "$APP/.env" ]] || { echo 'Missing private .env'; exit 1; }
grep -qx 'APP_URL=https://www.jonathanmahapa.co.za/fyndet' "$APP/.env" || { echo 'APP_URL must match FyndEt subdirectory'; exit 1; }
[[ ! -e "$WEB" || -f "$WEB/.fyndet-site-marker" ]] || { echo 'Existing unmarked directory; refusing to overwrite'; exit 1; }
mkdir -p "$APP/var" "$WEB"
chmod 700 "$APP/var"
backup="$APP/var/deploy-backups/$(date -u +%Y%m%dT%H%M%SZ)"
mkdir -p "$backup"
for name in index.php mount.php router.php .htaccess assets; do
 if [[ -e "$WEB/$name" ]]; then cp -a "$WEB/$name" "$backup/"; fi
done
echo "Existing FyndEt files backed up: $backup"
"$PHP" "$APP/bin/migrate-cpanel.php" --apply
cp -a "$APP/public/index.php" "$WEB/index.php"
cp -a "$APP/public/mount.php" "$WEB/mount.php"
cp -a "$APP/public/router.php" "$WEB/router.php"
chmod 755 "$WEB"
mkdir -p "$WEB/assets"
cp -a "$APP/public/assets/." "$WEB/assets/"
cat > "$WEB/.htaccess" <<'HTACCESS'
Options -Indexes
DirectoryIndex index.php
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteCond %{HTTPS} !=on
RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.php [END,QSA]
</IfModule>
<IfModule mod_headers.c>
Header always set X-Content-Type-Options "nosniff"
Header always set Referrer-Policy "strict-origin-when-cross-origin"
Header always set X-Frame-Options "DENY"
Header always set Permissions-Policy "camera=(), microphone=(), geolocation=()"
</IfModule>
<FilesMatch "(^\.|\.env$|\.sql$|\.log$)">
Require all denied
</FilesMatch>
HTACCESS
touch "$WEB/.fyndet-site-marker"
echo 'Code copied only into the dedicated FyndEt directory. Run production HTTP and worker tests.'
