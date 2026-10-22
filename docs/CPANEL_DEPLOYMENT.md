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
8. Run `composer install --no-dev --optimize-autoloader` in the private app folder (or upload a verified built vendor/ directory); run `php bin/preflight-cpanel.php` and resolve every FAIL. Enable backups and test restoration on staging before real customer data.

## Important launch blockers
- Email ownership verification and basic database-backed login throttling are implemented but require real outbound mail testing; MFA, CAPTCHA / bot detection and provider-independent abuse monitoring remain outstanding. **Not production approved** until these are implemented.
- Stripe hosted checkout and signed webhook subscription-state updates are implemented behind provider configuration, but portal/cancellation, renewal/dunning, refunds, taxes and integration tests are outstanding. No payments have been tested or activated.
- Public DNS checks are implemented. Optional GitHub exact-email public commit search and a Have I Been Pwned licensed API adapter are implemented, disabled by default. Their actual coverage, credentials, licence permissions, API reliability and UI must be validated on staging. This is not universal account discovery.
- Legally reviewed public policies and a proper contact address are missing.
- No live cPanel account details, host, DNS, domain, deployed certificate or production smoke test results have been supplied.
- Configure application-root handling for split webroot deployments.
- Implement data retention, account deletion, privacy request handling and incident response before public launch.
- Test payment security, account protections, queue faults and backups on staging.

## Rollback and data handling
Back up the release tree, database and private configuration separately before changes. Use an atomic release/symlink where supported, or stage and promote the public assets safely. Never replace unrelated websites, WordPress installations, DNS, mailboxes or existing databases. Stop the FyndEt cron before rollback. Revert files to last known good release, and run tested reverse migrations only where data safe. Securely archive then delete old investigation data according to retention rules.

## Explicitly forbidden claims
Do not say the site is fully production ready, subscription-functional, breached-data-enabled or deployed until these are independently verified.

## Optional source configuration
Set `GITHUB_PUBLIC_COMMITS_ENABLED=1` and a valid `GITHUB_TOKEN` only after verifying GitHub API terms, throttling and permitted scope. Set `HIBP_ENABLED=1` and `HIBP_API_KEY` only after verifying your paid provider licence and acceptable-use restrictions. Both adapters operate only on verified self-checks; never turn them on for unrestricted third-party email enumeration.

## Stripe configuration
Only where Stripe can legally serve the operating business: configure `STRIPE_SECRET_KEY`, `STRIPE_WEBHOOK_SECRET` and the three `STRIPE_PRICE_*` identifiers. Add `https://YOUR-DOMAIN/billing/webhook` as a production webhook, subscribe to `checkout.session.completed` and `customer.subscription.created`, `customer.subscription.updated` and `customer.subscription.deleted`; ensure signature verification and idempotent replay tests. Configure taxes, cancellations, customer portal, refunds and invoice policies before activating checkout. Never paste payment or API secrets into GitHub.


## Activation checklist
- Set `PAID_BILLING_ENABLED=0` throughout staging. Enable it only after the business can accept payments in its jurisdiction and all paid-plan entitlements, tax rules, cancellation, dunning, hosted portal and payments are tested.
- Set `SALES_EMAIL` to an actual mailbox controlled by the operating business. The repository does not assume ownership of fyndet.com or any other domain.
- Configure `FYNDET_APP_ROOT` for split-document-root installations and verify PHP receives the Apache environment variable.
- The current developer tests run against PHP and MySQL on GitHub Actions; these do not replace a production cPanel smoke test, backup restore, legal review or accessibility review.


## Daily maintenance cron
Schedule `bin/maintenance-cpanel.php` once daily with the verified cPanel PHP binary. This performs expiring-token cleanup, plan-based report retention, audit cleanup and maintenance health recording. Run it under the cPanel user, not as root. Do not enable deletion until you have tested database backups and applicable retention legal requirements.

## Platform administration
Admin access requires explicit `users.is_platform_admin=1` granted by a trusted deployment administrator after verifying account ownership. Do not provide a public 'become admin' control. Admin URL: `/admin`. Privacy requests require reviewed status updates, not automatic deletion of evidence needed for legal obligations.


## Multi-factor authentication
Authenticators compatible with RFC 6238 can use the manually displayed setup secret. MFA secrets are AES-256-GCM encrypted using `APP_KEY`; configure this with 64 random hexadecimal characters before opening registrations. **Do not rotate APP_KEY without a planned MFA re-enrolment process.** Users must securely retain one-time recovery codes. SMS is not required. Platform administrators should enforce MFA as an organisational operational policy.
