# FyndEt — Product Requirements

## 1. Product statement
FyndEt helps people and authorised investigators understand **documented, permitted public digital traces** associated with an email address. It goes beyond address syntax or mailbox checks, without guaranteeing universal platform-account discovery.

## 2. Primary audiences
- Individuals checking their own email exposure.
- Security and compliance teams conducting authorised domain/email exposure reviews.
- Investigators and brand-protection teams with documented legitimate grounds.
- Enterprise customers using contractual data-source and access controls.

## 3. Core journey
1. Visitor learns what is and is not discoverable; selects a plan.
2. User registers, verifies their email, accepts the acceptable use policy and completes any proportionate identity/organisation verification.
3. User enters the target email address and provides an allowed purpose and authority/consent attestation. Additional proof may be required for sensitive functions or targets.
4. API validates email, entitlements, rate/abuse controls and permission gates; reserves scan credit idempotently.
5. Job controller invokes only approved source connectors; reports partial failures.
6. Findings are normalised into source-linked evidence with timestamps, rights restrictions and confidence.
7. User reviews a summary and expandable evidence; can export only allowed fields.
8. Search is audited; results expire on a configurable retention policy. Data subject rights and deletion workflows remain available.

## 4. Investigation modules
**Email basics:** syntax validation and domain parsing; no unsupported claims that an inbox is active.

**Domain intelligence:** DNS MX/SPF/DMARC, provider classification where inferable, domain website/organisation facts if public; disclose lookup timestamp.

**Public account references:** authorised APIs, public documents/pages and opted-in datasets that explicitly permit linking to the email; provide URL, exact match method and rights context. Never silently probe sign-up/login/reset endpoints.

**Breach exposure:** approved breach notification/data providers, ordinarily restricted to self-check or verified domain/organisational authority. Show breach name, approximate date, affected data categories and mitigation tips where permitted; no passwords or raw leaked records. Some sources require independent domain verification or contractual authorisation.

**Other traces:** permitted public documents, code commit author metadata where compliant with provider terms, publicly available organisational directories and opt-in professional identifiers. Apply proportionality and sensitive-data filters.

**Risk summary:** evidence-based exposure indicators, not a person-level criminality or trust score. Display coverage and uncertainty.

## 5. Investigation results UX
- Top summary: email, scan status, date, checked sources, observed hits, unresolved or unavailable sources.
- Tabs: Overview, Public References, Exposure, Domain Intelligence, Sources & Methodology.
- Evidence cards: description, observed/possible label, source, retrieval date, match basis, disclosure constraints, user guidance.
- Downloadable reports with watermark, actor ID, scope, methodology, expiry and redactions.
- Distinguish `no observation` from `did not check`.

## 6. Site map
Public pages: /, /how-it-works, /features, /pricing, /faq, /contact, /legal/privacy, /legal/terms, /legal/acceptable-use.
App: /app, /app/investigations/new, /app/investigations, /app/investigations/[id], /app/reports, /app/billing, /app/settings, /app/team and /app/api-keys for eligible subscriptions.
Admin (isolated/role-protected): customers, plans, provider health, misuse alerts, usage, disputes, privacy requests and audit.

## 7. Non-functional requirements
Responsive from 320px, WCAG 2.2 AA, strong contrast, descriptive focus states, performant skeletons, structured errors, queued jobs with bounded retries, multi-tenant database isolation, encrypted data at rest/in transit, security headers, observability with PII redaction, documented backup and recovery targets.

## 8. Success metrics
Verified signup conversion, legitimate searches completed, source coverage, proportion of evidence-backed findings, median completion latency, report usefulness, paid conversion, cancellations, privacy requests handled within policy, abuse interventions and billing failure recovery.

## 9. Explicit exclusions
Unrestricted stalking/doxxing workflows; bulk harvesting of third-party addresses; account takeover workflows; credential exposure or acquisition; hidden account discovery using auth flows; bypass of platform anti-automation; inferential matching presented as fact.
