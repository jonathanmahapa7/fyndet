# FyndEt

**FyndEt — Investigate the digital footprint behind an email address.**

FyndEt is a planned subscription-based email intelligence and digital-footprint research service. It combines permitted public-source discovery, domain analysis, authorised breach-exposure checks and evidence-backed reporting into an approachable, professional investigation workspace.

> **Project status (22 October 2026):** cPanel-oriented application source committed, including a premium responsive interface, PHP sign-in/registration, database models, self-check investigation creation, CLI scheduled DNS analysis and private report viewing. The app now includes verified email ownership, password recovery, a signed Stripe checkout/webhook adapter, optional permitted public evidence adapters and plan-checked PDF/CSV exports. Billing, provider credentials, paid-plan feature completeness and live cPanel deployment still need real-world configuration and verification. Do not use with real customers yet.

## Product objectives
- Search an email address and produce a structured investigation with source attribution and provenance.
- Distinguish observed evidence, possible matches and unknown/unavailable results.
- Surface public account references, domain/organisation context, exposed breach indicators when authorised, and other permitted traces.
- Support individual self-checks, justified professional investigations and organisations with an approved lawful purpose.
- Offer Free, Starter, Pro, Business and Custom subscription plans.

## Pricing (USD, monthly)
| Plan | Price | Positioning |
| --- | ---: | --- |
| Free | $0 | Basic discovery and evaluation |
| Starter | $15 | Individual investigation workflow |
| Pro | $25 | Advanced investigation workflow |
| Business | $33 | Team workflow and controls |
| Custom | Contact sales | Contracted enterprise features |

All quotas are configurable; see [pricing specification](docs/PRICING.md). Providers' availability, cost, permissions and compliance requirements must be validated before claiming a feature is live.

## Documentation
- [Project and build instructions](AGENTS.md)
- [Product requirements](docs/PRODUCT_REQUIREMENTS.md)
- [Technical architecture](docs/ARCHITECTURE.md)
- [Pricing and entitlements](docs/PRICING.md)
- [Privacy, safety and acceptable use](docs/PRIVACY_AND_SAFETY.md)
- [Delivery and verification plan](docs/DELIVERY_PLAN.md)
- [SEO and AI-search readiness](docs/SEO_AEO.md)
- [Current release status and launch blockers](docs/RELEASE_STATUS.md)

## Application source
- Public website, plans, how-it-works, login, registration, and workspace: [public/index.php](public/index.php)
- Premium responsive stylesheet: [public/assets/style.css](public/assets/style.css)
- User/usage/investigation schema: [database/schema.sql](database/schema.sql)
- cPanel Cron investigation worker: [bin/process-investigations.php](bin/process-investigations.php)
- Installation and production blockers: [cPanel deployment](docs/CPANEL_DEPLOYMENT.md)
- Automated PHP lint instructions: [.github/workflows/quality.yml](.github/workflows/quality.yml)

The worker performs DNS configuration lookups for a **verified user's own email domain**. Optional exact-match GitHub public commits and licensed Have I Been Pwned metadata are disabled by default and require approved provider credentials. This is not equivalent to universal account discovery. Paid checkout is disabled by default; a signed webhook adapter exists but no live billing has been activated. Demo visuals are labelled illustrative.

## Contribution expectations
Keep changes scoped, avoid destructive repo updates, never commit secrets, and verify every asserted integration in staging before marking it functional.

## Test status
- PHP lint and Composer manifest validation: automated in GitHub Actions.
- PHP/MySQL integration: registration, email verification, authorised self-check, worker, report access restrictions, logout and password recovery.
- Browser-based desktop/mobile screenshot capture: automated, with artifacts retained for review.
- **Deployment and production acceptance remain outstanding.** See [cPanel runbook](docs/CPANEL_DEPLOYMENT.md).
