# External Asset and Dependency Review

Owner: David Cunningham (`info@truska.com`)

Next quarterly review: 15 December 2026

Cadence: review this inventory once per quarter and before any production release that changes a listed integration, its CSP allowlist, credentials, or payment flow. Record the review date and findings in the release notes or a Dev Task.

| Dependency / asset | Purpose | Source or configuration | Review focus |
| --- | --- | --- | --- |
| Bootstrap 5.3.2 | Public and admin UI CSS/JavaScript | `cdn.jsdelivr.net` | Pin version, review release/security notices, confirm CSP source remains required. |
| Font Awesome | Site and admin icons | `cdnjs.cloudflare.com`, `kit.fontawesome.com` | Review version/kit ownership and CSP sources. |
| Google Fonts (Manrope) | Web font delivery | `fonts.googleapis.com`, `fonts.gstatic.com` | Confirm font is still required and only the listed origins are allowed. |
| TinyMCE 6 | Rich-text administration | `cdn.tiny.cloud` | Review API key ownership, editor version, and server-side sanitiser coverage. |
| Stripe API / Checkout / webhook | Payments, payouts and payment requests | `api.stripe.com`; credentials in `../private` | Review API version, webhook signature handling, test/live keys, payout permissions and Stripe notices. |
| Facebook post embed | Ride-report gallery embeds | `www.facebook.com` | Confirm the restricted Facebook URL validation and `frame-src` allowlist remain sufficient. |
| Playwright 1.62.1 | Development browser tests | `package.json`, `package-lock.json` | Run dependency update review and test suite; do not ship as a runtime dependency. |
| PHP extensions | Application runtime (PDO MySQL, DOM/XML, JSON, mbstring, GD/fileinfo as used) | Server PHP installation | Review supported PHP release, extension updates, and production package security notices. |

## Review checklist

1. Compare pinned versions and vendor security advisories.
2. Remove unused assets and tighten the report-only CSP allowlist using reviewed `CSP_REPORT` entries.
3. Confirm credentials remain outside the web root and rotate any vendor secret when access changes.
4. Run the relevant smoke tests, payment test-mode checks, and browser tests after upgrades.
5. Record the reviewer, date, upgrades, exceptions, and next review date.
