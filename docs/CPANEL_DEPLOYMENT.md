# FyndEt — cPanel deployment runbook

**Status:** cPanel-ready application source exists, but no hosting account has been accessed and no live deployment has been verified. Never claim a deployment is complete solely because this runbook exists.

## Requirements
- cPanel account with Apache, PHP **8.2+** and extensions PDO MySQL, mbstring, openssl, json and standard DNS functions.
- MySQL or MariaDB database, database user and suitable privileges created in **cPanel > MySQL Databases**.
- Domain with HTTPS configured; cPanel File Manager / Terminal / Git Version Control or SFTP/SSH and Cron Jobs.
- Properly scoped filesystem access. No root/Docker/Redis/Node service required.

## Filesystem arrangement
Use a private application folder such as \`/home/CPANEL_USER/fyndet-app\` containing \`bin\`, \`database\`, \`.env\`, \`var\`, and repository docs. Make the **web-facing document root** point at \`fyndet-app/public\` if cPanel permits selecting that path.

If cPanel cannot point the domain document root at \`public\`, deploy only the **contents of \`public/\`** to the domain's document root and keep the remainder in \`/home/CPANEL_USER/fyndet-app\` outside the public directory. In that split layout, the current PHP \`dirname(__DIR__)\` app root reference **must be adapted and tested** before use, for example by reading a securely configured \`FYNDET_APP_ROOT\` environment setting. Do not place \`.env\`, database scripts or CLI workers inside the webroot.

## Installation
1. Create the database and database user. Grant only required database privileges.
2. Import \`database/schema.sql\` through phpMyAdmin.
3. Copy \`.env.example\` to \`.env\` **in the private application directory** and populate database settings and a cryptographically random \`APP_KEY\`. Do not commit \`.env\`. Restrict permissions (e.g. 0600).
4. Set the domain document root to \`public/\`, enable the intended PHP version and confirm PHP sessions and rewrite support.
5. Set the \`var/\` directory owner to cPanel account, permissions 0700.
6. Create a Cron Job, e.g. every five minutes:
   \`\`\`sh
   */5 * * * * /usr/local/bin/php /home/CPANEL_USER/fyndet-app/bin/process-investigations.php >> /home/CPANEL_USER/fyndet-worker.log 2>&1
   \`\`\`
   Replace the PHP binary with the **verified** cPanel-provided PHP CLI executable. Store logs privately and rotate them; use no public log path. cPanel may expose \`/opt/cpanel/ea-php82/root/usr/bin/php\`.
7. Test routing and sign-up; log in; perform a self-check; wait for the cron job or invoke the worker explicitly; verify a domain report with timestamps. Verify no secrets or SQL files are HTTP-accessible.
8. Enable backups and test restoration on staging before real customer data.

## Important launch blockers
- Registration lacks email ownership verification, MFA, password reset, CAPTCHA / sign-in throttling and provider-independent abuse monitoring. **Not production approved** until these are implemented.
- Billing checkout, subscription webhook synchronisation, refund/dunning and price enforcement are **not yet implemented**. Never charge customers by manually flipping the user plan.
- Only legitimate domain-level DNS indicators are implemented; public-account and breach providers are not connected. Avoid promising otherwise.
- Legally reviewed public policies and a proper contact address are missing.
- No live cPanel account details, host, DNS, domain, deployed certificate or production smoke test results have been supplied.
- Configure application-root handling for split webroot deployments.
- Implement data retention, account deletion, privacy request handling and incident response before public launch.
- Test payment security, account protections, queue faults and backups on staging.

## Rollback and data handling
Back up the release tree, database and private configuration separately before changes. Use an atomic release/symlink where supported, or stage and promote the public assets safely. Never replace unrelated websites, WordPress installations, DNS, mailboxes or existing databases. Stop the FyndEt cron before rollback. Revert files to last known good release, and run tested reverse migrations only where data safe. Securely archive then delete old investigation data according to retention rules.

## Explicitly forbidden claims
Do not say the site is fully production ready, subscription-functional, breached-data-enabled or deployed until these are independently verified.
