# FyndEt

**FyndEt — Investigate the digital footprint behind an email address.**

FyndEt is a planned subscription-based email intelligence and digital-footprint research service. It combines permitted public-source discovery, domain analysis, authorised breach-exposure checks and evidence-backed reporting into an approachable, professional investigation workspace.

> **Project status (22 October 2026):** cPanel-oriented application source committed, including a premium responsive interface, PHP sign-in/registration, database models, self-check investigation creation, CLI scheduled DNS analysis and private report viewing. Deployment, complete account security, subscription billing and authorised data-provider integrations remain unverified or unimplemented. Do not use with real customers yet.

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

## Application source
- Public website, plans, how-it-works, login, registration, and workspace: [public/index.php](public/index.php)
- Premium responsive stylesheet: [public/assets/style.css](public/assets/style.css)
- User/usage/investigation schema: [database/schema.sql](database/schema.sql)
- cPanel Cron investigation worker: [bin/process-investigations.php](bin/process-investigations.php)
- Installation and production blockers: [cPanel deployment](docs/CPANEL_DEPLOYMENT.md)
- Automated PHP lint instructions: [.github/workflows/quality.yml](.github/workflows/quality.yml)

The current worker performs live DNS configuration lookups **for the signed-in user's own email domain**. This is not equivalent to finding online accounts. Data breach checks, external provider discovery, payments and domain verification are not active. Demo visuals are labelled illustrative.

## Contribution expectations
Keep changes scoped, avoid destructive repo updates, never commit secrets, and verify every asserted integration in staging before marking it functional.
