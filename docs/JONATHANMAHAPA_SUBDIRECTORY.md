# FyndEt deployment at a subdirectory of existing jonathanmahapa.co.za

## Target and protection
- Requested URL: https://www.jonathanmahapa.co.za/fyndet
- Expected existing host root: /home/bathokwe/public_html/jonathanmahapa.co.za
- Web-facing FyndEt folder: /home/bathokwe/public_html/jonathanmahapa.co.za/fyndet
- Private FyndEt application: /home/bathokwe/fyndet-app

**The actual parent document-root path has not been verified on the server.** The deployment helper refuses to run if its expected path does not exist, if run by root or another user, or if an existing /fyndet directory lacks its FyndEt marker. It does not modify the parent website's index, rewrite rules, SSL, DNS or unrelated databases.

## What is ready in the repository
The public front controller loads mount.php to translate /fyndet routes into the existing application router, and prefixes root-relative links and form actions. Session cookies are scoped to /fyndet; redirects now honour the mount path. Apache uses SetEnv FYNDET_APP_ROOT=/home/bathokwe/fyndet-app and SetEnv FYNDET_BASE_PATH=/fyndet within FyndEt's own .htaccess.

## Server preflight, as cPanel user bathokwe
1. Verify your existing document root for jonathanmahapa.co.za; do not assume a path from prior logs.
2. Clone the repository to /home/bathokwe/fyndet-app outside the public HTML tree, without overwriting an existing checkout.
3. In the private folder, copy .env.example to .env (0600), set APP_URL=https://www.jonathanmahapa.co.za/fyndet, DB_* settings for a dedicated FyndEt database, random APP_KEY, mail sender and production environment settings. Do not commit secrets.
4. Install Composer dependencies privately; validate PHP 8.2+, MySQL, outbound mail, cURL, the session handler, Apache SetEnv and mod_rewrite.
5. Read-only check (under bathokwe):
   bash /home/bathokwe/fyndet-app/bin/deploy-subdirectory-cpanel.sh --check
6. After database backups, schema verification and safe hosting-path confirmation, install only into the dedicated FyndEt folder:
   bash /home/bathokwe/fyndet-app/bin/deploy-subdirectory-cpanel.sh --install

## Required verification before calling live
- GET /fyndet, /fyndet/pricing, /fyndet/register, /fyndet/robots.txt and /fyndet/sitemap.xml return expected status and HTML/XML, without root-level redirects.
- Styles, JS, favicon load only from /fyndet/assets. No horizontal overflow or login/checkout navigation failures on mobile.
- Session cookie Path=/fyndet, CSRF, email verification, sign-in/out, password reset, MFA and private report access work.
- Database migrations, worker cron and monitoring scheduler run as bathokwe under the correct PHP interpreter.
- Requests for /fyndet/.env and private code return denied/not-found, and the parent site's homepage and applications remain unchanged.
- Outbound email, billing, source-provider restrictions, accessibility and privacy checks pass before the site is released to paying customers.

**Deployment is not complete from GitHub commits alone.** A real SSH/cPanel connection is required to transfer and verify files. This current assistant environment has no live /home/bathokwe mount or cPanel SSH tool.
