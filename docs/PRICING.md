# FyndEt — Subscription Pricing and Entitlements

All amounts USD per month, before applicable taxes. Plans are original FyndEt offerings. No claim is made that these reproduce competitor quotas or pricing. **Initial proposed entitlements must be reviewed against provider costs before publication or activation.**

| Feature | Free — $0 | Starter — $15 | Pro — $25 | Business — $33 | Custom |
|---|---|---|---|---|---|
| Monthly investigation credits | 2 | 25 | 100 | 250 | Negotiated |
| Searches per minute (server-enforced) | 1 | 2 | 4 | 6 | Negotiated |
| Source coverage | Basic approved sources | Standard approved sources | Extended approved sources | Extended approved sources | Contracted |
| Breach exposure | Self-verification only; limited | Where authorised | Where authorised | Verified organisational authority | Contracted and approved |
| Save investigation reports | 7 days | 30 days | 90 days | 180 days | Contracted |
| Exports | Summary only | PDF | PDF, CSV | PDF, CSV | Custom |
| API access | No | No | Limited | Yes | Contracted |
| Seats included | 1 | 1 | 1 | 3 | Negotiated |
| Team roles and audit | No | No | Individual audit | Team roles and audit | SSO/advanced audit by contract |
| Support | Help centre | Email | Priority email | Business support | SLA by contract |

**Credit model:** one credit is reserved for an admitted investigation, and charged once a meaningful provider execution begins. Duplicate job delivery must never double-charge. Failed-before-start and platform-fault-only requests refund credits; transient partial results require an explicit fair-use and refund policy. Monthly credits reset on billing-period boundaries and do not roll over by default.

**Entitlement enforcement:** centralised server guard for user, org, plan, subscription state, seats, credit balance, rate limits, permitted sources, report retention and exports. No entitlement based solely on frontend fields.

**Upgrade/downgrade:** proration follows payment-provider policy and is presented to the customer before confirmation; downgrades scheduled for cycle end by default. Cancellation stops renewals, retains permitted access until period end, then changes entitlements. Failed payments use a clearly documented grace period and dunning. Refund rules displayed before checkout.

**Checkout:** integrate a properly configured payment gateway (Stripe Billing where supported, otherwise regional equivalent). Validate webhook signatures and process events idempotently. Never store raw payment card data. Taxes/VAT, invoice details, price IDs, trials and currency display require launch configuration.

**Custom:** sales-assisted quotation for high volumes, enterprise security review, authorised specialist connectors, dedicated retention arrangements, procurement, seat limits and negotiated SLAs. No unlimited access to private personal data.

**Free-tier privacy:** very low quotas, anti-enumeration controls, minimum output, stronger verification and no raw sensitive breach details.
