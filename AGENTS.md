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
