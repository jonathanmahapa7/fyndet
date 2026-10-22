# FyndEt

**FyndEt — Investigate the digital footprint behind an email address.**

FyndEt is a planned subscription-based email intelligence and digital-footprint research service. It combines permitted public-source discovery, domain analysis, authorised breach-exposure checks and evidence-backed reporting into an approachable, professional investigation workspace.

> **Project status:** Product specifications and implementation instructions. No live investigation engine, paid subscription processing, or real-time provider integration is claimed to be operational.

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

## First implementation milestone
Implement the design system, accessible marketing pages, pricing and registration flows, authenticated dashboard and investigation flow using **clearly marked synthetic fixtures**. Implement provider adapters and billing only after required keys, supplier contracts, payment configurations, privacy reviews and tests are in place.

## Contribution expectations
Keep changes scoped, avoid destructive repo updates, never commit secrets, and verify every asserted integration in staging before marking it functional.
