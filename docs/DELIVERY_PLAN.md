# FyndEt — End-to-End Delivery Plan

This document is a build sequence and launch checklist, not a claim of completed implementation.

## Phase 1 — Brand and product shell
- [ ] Initialise TypeScript application, package manager locks, formatting, lint and CI.
- [ ] Build distinct FyndEt identity, accessible responsive design tokens and components.
- [ ] Build Home, How It Works, Features, Pricing, FAQ, Contact and legal pages.
- [ ] Build accessible investigation dashboard with synthetic, labelled fixtures.
- [ ] Verify 320px to desktop layouts and colour contrast.

## Phase 2 — Accounts and subscriptions
- [ ] Auth with verification and MFA, sessions and roles.
- [ ] PostgreSQL schema, migrations, tenant isolation and seeded plan catalogue.
- [ ] Free/Starter/Pro/Business/Custom entitlements, credit ledger and seat controls.
- [ ] Live gateway configuration, checkout, signed webhooks and portal; test renewal, failure and cancel states.
- [ ] Email notifications for account, invoice and service actions.

## Phase 3 — Investigation engine
- [ ] Input validation, allowed-purpose workflow and legal-authority gate.
- [ ] Durable async execution, circuit breakers, retries and cost ceilings.
- [ ] MX/SPF/DMARC DNS module with accurate timestamps.
- [ ] Signed-off public-reference adapters with permitted provenance.
- [ ] Compliant breach-exposure provider behind verified control restrictions.
- [ ] Findings normalisation, evidence strength, source health and partial-results UX.

## Phase 4 — Reports, team and administration
- [ ] Tenant-safe history, search and pagination.
- [ ] PDF/CSV exports with redaction and expiry.
- [ ] Business team invitations, role assignment, usage and audit.
- [ ] API access, rotating keys, quotas and scoped endpoint permissions.
- [ ] Admin telemetry, provider monitoring, support, disputes and abuse queue.

## Phase 5 — Hardening and production
- [ ] Unit, contract, integration, E2E, billing and fault-injection suites.
- [ ] Cross-tenant and privilege escalation tests.
- [ ] Privacy impact assessment, third-party licensing, policy and retention review.
- [ ] Performance, accessibility, OWASP ASVS and penetration testing.
- [ ] Backups, restores, alerting, status page and incident response drills.
- [ ] Staging proof and limited beta; production cutover only after approvals.

## Quality gates
No fake live results; no insecure lookup endpoints; no raw credential data; no undocumented source claims; no paid feature without enforcement; no privacy-policy promise without implementation.

## Current repository status
The repository was empty when these project documentation files were initiated. This commit establishes a concrete specification; it does **not** implement or deploy the service.
