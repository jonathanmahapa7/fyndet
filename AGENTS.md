# FyndEt — Project Instructions

## Mission
Build FyndEt as a premium, subscription-based email intelligence and digital-footprint investigation platform inspired by the **category and capabilities**, not copied branding, code, content or exact interface, of emailosint.org.

## Non-negotiable working rules
1. Do not change or remove files, features, infrastructure or decisions outside the explicit task scope.
2. Read README.md and the documents in docs/ before implementing code. Inspect the repository before modifications.
3. Prefer complete vertical slices (UI, validation, services, persistence, entitlements, tests and documentation) over disconnected mockups.
4. Build an enterprise-quality user experience: typography, legible contrast, intentional hierarchy and whitespace, responsive layouts, keyboard accessibility, error/empty/loading states, reliable data tables and exportable reports.
5. Never present simulated, guessed or unsupported associations as authentic investigation findings. Mark fixtures/demo data clearly.
6. Enforce privacy and lawful-use guardrails in the API and job worker, never only in the UI.
7. Do not promise a universal email-to-account enumeration capability. Provider coverage is conditional on authorised access and technical feasibility.
8. Never collect or display passwords, recovery tokens, private credentials, private messages or raw breach dumps. Do not build password-reset probing, login-enumeration automation, CAPTCHA bypass or stealth scraping.
9. Use only sources that allow the intended use, with written provider agreements when required. Record provenance, collection date, permission basis and confidence for every finding.
10. Require verified customer identity as appropriate, documented lawful purpose, search attestation, rate limits, misuse detection, abuse reporting and audit logging; sensitive outputs require stronger access controls.
11. Free-tier results must be minimal, redacted and abuse-resistant. Paid tiers do not override privacy controls.
12. Test subscription state transitions, seat and credit enforcement, idempotency, failed payments, job retries, cancellation and report access controls.
13. Never commit API keys, database credentials, tokens or personal investigation results. Supply .env.example with non-secret placeholders if code is added.
14. Every delivery should state changed paths, verification performed, remaining blockers, and what remains non-operational. Do not label something production-ready until tested.
15. Default site language: English; prices: USD; prepare for region-aware privacy and billing, including POPIA for South African operations.

## Product scope
- Public: Home, How It Works, Capabilities, Pricing, FAQ, Contact, Privacy, Terms, Acceptable Use, Sign In and Register.
- Authenticated: Overview, New Investigation, Investigations, Report Detail, Saved Reports, Billing, API Keys (eligible plans), Team & Roles (eligible plans), Settings and Privacy Requests.
- Operations: subscription & entitlements, provider catalogue, scan orchestration, audit trail, abuse queue, support tooling and data retention controls.
- Reports: source evidence, associated public accounts (when observed and permitted), breach indicators from compliant sources, domain intelligence, timestamped traces, confidence, redaction and export.

## Preferred implementation baseline (subject to actual hosting review)
TypeScript monorepo; Next.js with App Router for marketing and app; Tailwind CSS/design tokens and accessible UI primitives; PostgreSQL with migration tooling; Redis-backed job queue where hosting supports it; server-only provider connectors; Stripe Billing or another approved billing gateway. Separate long-running workers from request handlers. Choose cloud infrastructure only after deployment constraints are confirmed. Strict runtime input schemas, session/role enforcement and centralised audit logging.

## Design direction
Distinctive FyndEt identity. Professional intelligence/workspace aesthetic with high-contrast typography and measured visual density, dark/navy base and restrained accent colour, clear evidence labels and polished charts. Mobile-first and WCAG 2.2 AA target. Do not copy reference-site assets or layouts verbatim.

## Evidence rules
Finding state: `observed`, `possible`, `not_found`, `not_checked`, or `source_unavailable`. `not_found` means the specified source yielded no observation, not that an account does not exist. Every observed item requires evidence metadata: provider, source URL/reference (where permissible), retrieval time, observation type, confidence/rationale, visibility/redisclosure rights, and expiry. No automatic conclusion of identity linkage from a similar username or an email-shaped string alone.

## Build order
1. Inspect baseline & create app skeleton, token system and routes.
2. Auth, verified accounts, roles, organisation models and ownership enforcement.
3. Plan catalogue, entitlements, billing webhooks and transactional ledger.
4. Investigations API, async jobs, provider interfaces, evidence model, reports and exports.
5. Privacy guardrails, moderation/abuse, retention, data subject workflows.
6. E2E tests, CI, observability, staged deploy, security checks and launch approval.

## Definition of done
Real tests pass; keyboard and mobile tested; API authorization and tenant isolation tested; permission-aware source coverage documented; no secrets in repo; entitlement, billing and retention cases exercised; providers accurately described; demo fixtures never masquerade as real results; operational runbook supplied.


## Critical directive — premium design and cPanel deployment (2026-10-22)
- **Look and feel are paramount.** Produce a genuinely premium, differentiated digital-intelligence brand, not a generic dashboard, boilerplate template or utilitarian form. Treat art direction, typography, scale, micro-interactions, immaculate spacing, strong colour contrast, polished dark/light surfaces, information hierarchy, rich but restrained visualisations and mobile execution as first-class acceptance requirements.
- The complete experience must be cohesive across public marketing pages, pricing, checkout, authentication, app navigation, investigation steps, findings, reports, billing, account settings, team views, contact and error/empty/loading states. Avoid decorative dashboards with nonfunctional controls.
- Test at desktop, tablet and mobile widths; visually inspect actual rendered screens before release. Fix overflow, clipped text, barely visible labels, poor contrast, cramped controls and inconsistent layouts. No design sign-off without screenshot review and keyboard accessibility testing.
- **cPanel hosting is mandatory.** Production delivery must work on a standard cPanel-managed hosting account; do not assume Vercel, Docker, root access, long-lived Node.js processes, Redis or separate workers are available. Adopt a **PHP 8.2+ / MySQL or MariaDB / Apache** production baseline unless verified hosting capabilities justify an alternative. Use Composer dependencies only where cPanel install/deploy is supported, and bundle built frontend assets for Apache.
- Implement durable background processing using idempotent **cPanel Cron Jobs**, database-backed queues, bounded PHP CLI invocations and locking; use native mail/approved SMTP integrations and cPanel-suitable storage under the account, outside public webroot for secrets and private reports.
- Deployment must include safe configuration, SQL migrations, dedicated .env outside public_html, a release/update script compatible with restricted cPanel shells, .htaccess routing and security headers, cron installation instructions, HTTPS requirements, a backup/rollback plan, and production smoke tests. Never overwrite unrelated cPanel apps, WordPress installations, SSL, DNS, mailboxes, databases or virtual hosts.
- **Do not stop at documentation or wireframes:** proceed through real implementation, integration, verification and cPanel deployment readiness, keeping unfinished provider contracts, gateway keys, server credentials or approvals explicitly identified. Do not fabricate tests or claim live operation without proof.
- Prioritise complete, usable flows, graceful fallback and truthful status labels over theatrical mock data. Payment flows and source adapters must only be switched to 'live' once authorised credentials and verification exist.

## Mandatory continuation and launch requirements — 22 October 2026
- **Complete the entire FyndEt service, not merely a prototype:** public site, authenticated investigation application, verifiable evidence adapters, subscriptions, accounts, administration, organisation seats, operational jobs, audit, privacy/retention controls, reporting, API entitlements, production documentation and staging tests. Implement the remaining work end to end in working code, not only in planning documents. Proceed through clear implementation tasks without unnecessary approval checkpoints. Do not fabricate operational status for integrations that require third-party licences, keys, real infrastructure or legal review.
- **Mobile-first is a launch gate.** All features must be operable and legible from 320px through large desktop displays, with no unintended horizontal scrolling, clipped content, invisible contrast, tiny controls or hover-only functionality. Test registration, investigations, results, billing, team administration and exports on mobile. Target WCAG 2.2 AA and real-browser screenshot regression testing.
- **Search engine SEO is mandatory.** Implement crawlable server-rendered public pages, distinctive titles/descriptions, semantic heading hierarchy, self-referencing canonicals, robots.txt, XML sitemap, Open Graph/Twitter sharing data, JSON-LD structured data where truthful, performance optimisations and analytics-consent boundaries. Keep login, billing, reports, sensitive identifiers, searches, and administration out of search indexes. No deceptive schema, keyword stuffing or indexed private data.
- **LLM / answer-engine discoverability (AEO/GEO) is mandatory.** Provide coherent concise machine-readable product explanations, well-structured FAQs, verifiable descriptions of coverage, stable public links, source transparency, author/operator and contact details once verified, and optional llms.txt as a navigational supplement rather than a guaranteed ranking mechanism. Allow compliant public-content crawlers in robots policy while never publishing private investigation data. Avoid claims of guaranteed placement or AI citations.
- **Premium look and feel is an acceptance criterion**, not decoration: visual QA of actual screenshots, bespoke but maintainable identity, excellent typography, hierarchy, motion preferences, whitespace, contrast, accessibility, mobile navigation, state design and realistic polished authenticated flows.
- **cPanel deployment must remain the default.** Apache / PHP 8.2+ / MySQL-MariaDB / scheduled cron workers, private application secrets, safe asset/webroot separation, verified CLI paths, migrations, safe deploy and rollback with no unrelated website modifications.
- Finish work with recorded tests, accurate deployment status, unresolved external dependencies and a user-facing link to committed files. Never describe an unconfigured provider or payment product as live.

## Multi-source email discovery standard — 22 October 2026
- The investigation engine must support broad, modular, independently tested sources for verified self-checks: detailed mail/domain DNS and posture, optional public certificate transparency, authorised indexed-web references, indexed documents, professional/community mentions, licensed public code history and licensed breach-exposure metadata. Provide source-attributed observations, timestamps, uncertainty and explicit status for sources not checked.
- Expand further using contracted providers, opt-in platform authorisation, historical archives and verified-domain organisational datasets only where permissions and data-protection obligations are established. Never probe account-registration, password-reset, login or platform anti-bot defences for account existence.
- Correlate findings conservatively. An email string found on a webpage or commit is **not** a confirmed linked account. Distinguish authoritative verified platform associations from indexed third-party references, possible matches and pure domain infrastructure.
- Discovery QA must exercise active source integrations with approved real credentials on controlled test subjects, bounded error handling (including 401/403/429), provenance checks, source coverage and mobile report rendering. Never substitute synthetic or unconfigured results for live coverage.
- The label **Advanced** may be used in assessments only where genuine measured source breadth, real licensed activation, precision, recall/coverage limitations and operational reliability support it. Record any blocked or inactive source transparently.
- Implement opt-in recurring verified-email checks using cPanel Cron; enforce monthly credits, tenant access, consent and retention controls. Compare stable evidence fingerprints rather than timestamps to detect material changes.
- Have I Been Pwned requires a genuine API key and adherence to its published terms and source-attribution requirements. Never commit provider secrets, misreport 401/403/429 as no breaches, or enable an unlicensed integration by faking a subscription.

## Competitive benchmarking is NOT limited to OSINT — 22 October 2026
- **EmailOSINT is an example, not the ceiling or sole competitor.** Research beyond traditional OSINT sites and benchmark against the best relevant capabilities from digital risk protection, cyber threat intelligence, identity exposure and attack-surface monitoring, identity security, breach intelligence, public-web and archival research, brand and impersonation protection, fraud signals, security ratings, investigative case management, and sophisticated B2B SaaS.
- Review best-in-class **product interactions and visual execution** from premium enterprise software, even outside cybersecurity. Do not clone third-party branding, layouts, protected data or misleading claims. Differentiate FyndEt through distinct brand quality, evidence provenance, usability, precise trust boundaries and transparent source coverage.
- Assess potential source breadth along independent dimensions: verified-account connections, consented account security, owned-domain infrastructure, licensed breach exposure, public web and documents, corporate references, historical information, impersonation signals, optional risk scoring, continuous monitoring, explainable evidence correlation and case/report workflows. Evaluate each for lawful access, reliability, precision, scale, commercial rights, tenant isolation, cost and feasibility on cPanel.
- Maintain a capability-by-capability benchmarking matrix with sourced, verifiable links; do not assert a competitor feature without evidence and do not treat a benchmark as permission to collect restricted third-party personal information.
- Seek **premium, ambitious, differentiated enterprise depth** across discovery, analytics, reports, administration, billing, support, mobile experiences, search-engine SEO and AI-search visibility. Never substitute an attractive mock screen or an unused adapter for functioning coverage.
- Record maturity using observed results: advanced only when implementation, authorised providers, live tests, measurable coverage and performance justify that label. Keep disabled and unavailable sources visible in diagnostics.
