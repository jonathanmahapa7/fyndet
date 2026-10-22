# FyndEt — Search Engine SEO and AI Search Discoverability

## Mandatory production principles
FyndEt marketing content must be useful, original, factual, transparent and indexable on a verified HTTPS domain. Investigations, emails, personal data, session pages, account recovery, webhook endpoints, API content, billing and administration must **never** be indexed or added to a sitemap.

## What has been implemented
- Route-specific title and meta descriptions for public pages
- Canonical URLs conditioned on a verified configured HTTPS APP_URL
- Open Graph and Twitter metadata
- Semantic, server-rendered HTML and crawlable internal navigation
- Dynamic sitemap at /sitemap.xml, only when APP_URL and production environment are correctly configured
- /robots.txt with private route exclusions, and a fail-closed Disallow all for nonproduction/unconfigured hosts
- X-Robots-Tag and robots meta noindex for private routes
- Source-transparent public explanations and FAQs
- /llms.txt as a plain-language navigation supplement; no claim it improves model ranking
- Responsive layout and browser screenshot regression checks

## Final live domain requirements
1. Confirm domain ownership and real HTTPS address in APP_URL; avoid example domains in canonical tags.
2. Confirm robots.txt, sitemap.xml, pages and navigation return HTTP 200 and have consistent canonical URLs.
3. Register domain with Google Search Console and Bing Webmaster Tools using owner-authorised methods; submit sitemap. This cannot be automated without actual verified accounts and domain control.
4. Add first-party analytics only after transparent consent and privacy review. Exclude investigation metadata, user emails and secret URLs from analytics payloads.
5. Measure Core Web Vitals, LCP, CLS and INP using production mobile devices and field data; review image weight, font preloading, caching and Apache configuration.
6. Publish actual legal operating name, contact details, privacy notice, methodology, evidence limitations and independently verified product coverage.
7. Publish responsible editorial resources addressing how domain intelligence, public traces and authorised breach notification work. Avoid repetitive thin pages intended only to target search keywords.
8. Verify social previews, favicon, page descriptions and robots rules from a public browser and search crawler. Monitor indexing issues and fix canonical conflicts.

## AI-search / answer-engine readiness
- Use meaningful headings, concise definitions, method explanations and citations to original public sources.
- Make uncertainty and provider coverage clear. Do not describe third-party data as universally available.
- Prefer transparent first-party FAQs and genuine examples over fabricated testimonials or authority signals.
- Machine-readable structured data must accurately reflect visible content; do not use misleading review, price or service-availability schema.
- Keep /llms.txt as an optional convenience. Search engines and AI systems are not obligated to read it, nor does it guarantee citations.
- Never authorise AI crawlers to retrieve authenticated or private investigation reports.
- Test AI-search descriptions periodically against real published pages and correct inaccuracies.

## Target public pages
Home, How It Works, Pricing, Contact and legally reviewed Privacy/Terms. Paid subscriptions and source integrations should only be described as live after activation. The pricing page must disclose when checkout is unavailable.

## Acceptance
This project is not SEO complete merely because meta tags exist. Real domain crawlability, mobile indexing, Search Console/Bing verification, performance measurements, privacy review and truthful user-facing content must be checked after cPanel deployment.
