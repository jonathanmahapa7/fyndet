# FyndEt — Release Status and Final Launch Gates

**Recorded:** 22 October 2026
**Repository:** https://github.com/jonathanmahapa7/fyndet
**Production status:** NOT DEPLOYED / NOT READY FOR PUBLIC PAID SUBSCRIPTIONS

## Implemented application code
- Bespoke dark premium responsive homepage, features, How It Works, pricing, FAQ, contact, privacy overview, registration, sign-in and application dashboard.
- Mobile layout, functional menus, responsive investigation forms, error states, evidence views and private workspace navigation.
- PHP 8.2+ / Apache / cPanel-first runtime with PDO MySQL, private env, SQL migrations, database-backed investigation queue and idempotent cron worker.
- Owner-only verified email investigation model, domain DNS evidence, optional permission-gated GitHub public commit metadata and optional licensed Have I Been Pwned notification metadata.
- Source-labelled reports, status/uncertainty disclosure, investigation history, plan-gated PDF and CSV exports and self-service personal data portability.
- Free, Starter, Pro, Business and Custom prices; metered monthly investigations and transactional credit reservations; account-bounded API tokens and self-check API.
- Stripe hosted subscription session / webhook / billing portal adapters, disabled until approved production configuration; no live billing claims.
- User registration and verified email ownership, password recovery, login throttling, AES-GCM encrypted time-based MFA and one-time recovery codes.
- Business organisation creation, invitation acceptance restricted to matching verified emails, roles, included-seat limits, and shared business usage allowances.
- Admin review interface for privacy requests and job states; user privacy requests, report deletion and automated report-retention maintenance.
- Read-only cPanel preflight, checksum-tracked migrations, dedicated-site deployment helper with backup and existing-site protection.
- Route-specific technical SEO, canonical URLs, robots and sitemap rules, schema.org data, FAQs, favicon and llms.txt.
- CI checks: PHP syntax, Composer manifest, real MySQL integration scenarios and Playwright mobile/desktop screenshot validations.

## Not yet demonstrated or operational
1. **Live cPanel account setup:** No production domain, hosting account, selected document root, PHP path, MySQL database, private environment, SSL verification, cron installation, mail delivery, filesystem safety, backup restoration or live smoke test has been exercised. The repository is not itself a deployed website.
2. **Payment operation:** Stripe account availability for the operating jurisdiction, real recurring-price objects and keys, customer portal, invoices, tax, payment failures, refund workflow and business approval are not fully confirmed or tested with an actual payment provider. PAID_BILLING_ENABLED defaults to 0.
3. **Data-source licences and coverage:** API access, applicable terms, provider limits and live results must be verified. GitHub and licensed breach adapters default OFF. Universal discovery of accounts associated with arbitrary third-party addresses is not promised.
4. **Legal release:** Publish operator-approved identity, domain, support details, POPIA/privacy notice, contracts/terms, retention exceptions, breach response process and complaint handling. Current legal page is an interim overview, not a final reviewed legal notice.
5. **External security testing:** Conduct real production penetration testing, email delivery tests, multi-tenant abuse cases, load tests, recovery drills, MFA recovery support and logging/monitoring acceptance. Passing development CI is not equivalent to security certification.
6. **Enterprise/custom features:** SSO, negotiated SLAs, enterprise provisioning, custom provider contracts and procurement/invoicing must be agreed and implemented per contract.
7. **Production SEO acceptance:** Verify public HTTP responses, robots.txt and sitemap from the intended domain, Google Search Console/Bing ownership, Core Web Vitals, legal identity, real social previews and mobile field performance. AI citation/ranking cannot be guaranteed.

## Launch sequence
1. Verify cPanel resources and dedicated domain/document root.
2. Create only the FyndEt database, validate private .env and outbound domain sender, verify APP_KEY.
3. Perform a full database/files backup; apply migrations using bin/migrate-cpanel.php.
4. Deploy only to the marker-verified FyndEt root with bin/deploy-cpanel.sh; never touch unrelated sites, DNS, SSL or mail services.
5. Install cPanel cron jobs for investigation processing and scheduled maintenance; inspect logs and locks.
6. Execute production registration, verification, MFA, password reset, job processing, authorised evidence, reports, API and team checks.
7. Complete payment and provider licencing/testing; enable paid billing and intelligence connectors only after approved tests.
8. Perform operational, privacy, security, accessibility, mobile and SEO acceptance and obtain release sign-off.

## Acceptance principle
FyndEt is finished when the **live product actually performs** the authorised investigation, subscription and operational workflows across cPanel and real providers, not simply when the code or documentation exists. Record all launch test outputs and sign-offs before claiming completion.

## Discovery expansion — 22 October 2026
Expanded investigation worker now performs A/AAAA/MX/NS/CAA/TXT and SPF/DMARC/MTA-STS/TLS reporting, with optional exact-email public web indexing via an approved Brave Search API subscription, GitHub commit references, and licensed HIBP breach metadata, subject to verified ownership. Source status and provenance are recorded. Web, GitHub and HIBP provider access default off until authorised credentials are configured. No source should be called Advanced unless live testing confirms real breadth, accuracy, entitlement, rate limits and user coverage.
