# Discovery Source Coverage and Activation

The evaluation matrix below is an **operational truth table**, not a marketing score. A source is labelled Advanced only after credentials, permitted scope, verified real data, test coverage, and stable performance are confirmed.

| Category | Implemented sources | Operational readiness |
|---|---|---|
| Detailed domain configuration | DNS A/AAAA/MX/NS/CAA/TXT and SPF/DMARC/MTA-STS/TLS-RPT published policies | Implemented; needs cPanel production checks |
| Public indexed web footprint | Optional Brave indexed web exact-email references | Built; API credentials and acceptable-use configuration outstanding |
| Public software development history | Optional GitHub exact-email commit-author source | Built; token and live rate/coverage testing outstanding |
| Breach exposure | Optional licensed Have I Been Pwned account metadata | Built; requires real paid API key and provider approval |
| Certificate transparency | Optional public crt.sh domain certificate records | Built; disabled by default; domain context only |
| Social and professional identity | Public-indexed mentions may be surfaced from licensed web search; no platform-specific account discovery | Incomplete; does not imply verified account membership |
| Historical traces | Time-stamped public commits, certificates and breach dates where the source is available | Partial; no comprehensive history service |
| Cross-source correlation | Evidence counts, source states, record provenance and uncertainty | Initial correlation implemented; identity resolution remains limited |
| Continuous monitoring | Scheduled job infrastructure exists, but automated per-account change alerts have not been validated | Incomplete |

## Why breach evidence is not automatically enabled
HIBP requires an authenticated subscription key and correct User-Agent for account-level breach searches, with provider-defined plans, usage restrictions and rates. No subscription key is present in this public repository and it must not be committed. The source is intentionally disabled by default in `.env.example`.

To activate **after** obtaining permitted API access on the actual server, set:
```ini
HIBP_ENABLED=1
HIBP_API_KEY=<operator-held 32-character HIBP subscription key>
```
Then perform an approved self-check against an account controlled by the tester. Verify source attribution, 200/404/401/403/429 handling, plan entitlements and audit logging before accepting paid customer requests. A 404 response is *no match in the checked source*, never proof of no breach anywhere.

## Optional public web search
```ini
BRAVE_SEARCH_ENABLED=1
BRAVE_SEARCH_API_KEY=<approved search subscription credential>
```
Only enable this for approved self-check searches and after assessing provider terms and cost. Exact indexed mentions do not establish account membership or identity.

## Optional certificate transparency
```ini
CERTIFICATE_TRANSPARENCY_ENABLED=1
```
This finds some public certificate names relating to the **email's domain**, not accounts. It must not be described as social-account discovery.

## Quality gate
No source, capability or full investigation engine is called **Advanced** unless actual live tests establish that claim. Missing credentials, licences, permissions or coverage are explicitly reported as Not checked or Unavailable.

## Future broad-source priorities
Contracted public-data search, verified-domain enterprise exposure feeds, opt-in platform integrations, additional public-record providers, historical archives, deduplication and scheduled change notifications. Implement only through authorised APIs or disclosed public sources; avoid login/reset probing, credential dumping, anti-bot bypass or indiscriminate harvesting.

## New federated query families
Where the licensed indexed search provider is configured, FyndEt now checks exact-email references across general web results, indexed PDFs, professional context and community context, retaining source URLs and uncertainty. These are distinct queries, not distinct social-account APIs. The optional crt.sh adapter adds certificate transparency metadata for the email domain only.

Run `php bin/check-discovery-providers.php` privately on cPanel to see configured source gates without exposing secrets. It does not verify paid licence validity.

**Storage licensing:** Brave Search notes that storing search API results requires explicit storage rights under an eligible plan. Because FyndEt persists evidence for reports, indexed web adapters require BRAVE_SEARCH_STORAGE_LICENSE_CONFIRMED=1 as well as access credentials. Leave disabled until the contract explicitly authorises retention. See https://brave.com/search/api/ .

## Zero-budget operating baseline
The default paid-service product must not depend on commercial OSINT feeds or licensed search APIs. Implementable no-cost primary evidence includes local DNS mail-security policy analysis, publicly published infrastructure signals where automated commercial access is permitted, account-owner supplied evidence, and internal change detection. SPF and DMARC posture interpretation is implemented without a remote subscription.

Important: 'free API' is not synonymous with 'commercially usable'. RIPEstat's public data API, for example, excludes commercial derivative products without written permission. Do not incorporate it as a no-cost commercial dependency. Certificate transparency search providers may likewise have personal-use-only free tiers. The certificate-search adapter remains opt-in until its intended usage is confirmed. No paid provider is part of the required operation.

**Honesty:** completely free comprehensive platform-by-platform account discovery and universal breach exposure are not available merely by querying public infrastructure. Do not advertise coverage the product does not actually possess.
