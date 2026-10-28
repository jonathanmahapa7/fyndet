# FyndEt R0 discovery coverage

This is **not** a comprehensive digital footprint search. The default product makes no automated requests to public search engines and does not use paid search APIs, GitHub or DNS investigation.

## What runs

* Customer-directed click-through searches on Google, Bing, DuckDuckGo, Brave and Yandex; all requests are made in the customer's browser.
* Authenticated, quota-limited user-submitted public URLs, queued for asynchronous verification, with safe destination checks, robots.txt handling, exact-email/obfuscation matching, audit trails and per-URL dispositions.
* No unapproved third-party discovery source is activated in the R0 pipeline.
* Gravatar remains restricted to appropriately authorised, verified self-checks under its free-use policy; OpenPGP is disabled pending source clearance.

## Report states

`partial_coverage` means limited available sources cannot establish comprehensive negative coverage; `not_configured` means no approved source is configured; `not_checked` is a policy restriction; `source_unavailable` is a failed provider check; `observed` requires qualifying evidence. A thin source collection must never produce `not_found`.

A user-submitted public mention is not evidence of account control or identity. `user_reported` means the URL was supplied but source content could not be verified. Every result must include a verifiable source and its evidence limitation.

## Operations

Set `FYNDET_URL_INTAKE_ENABLED=1` in the private `var/fyndet-r0-flags.ini` to allow intake. `FYNDET_URL_INTAKE_ADMIN_ONLY=1` restricts use to the verified administrator email; switching to 0 must follow an explicit privacy/operational release decision. Workers process queued URLs asynchronously. Opt-outs are recorded by hashed target email in `fyndet_url_suppression`. Never ingest unrelated addresses in bulk.
