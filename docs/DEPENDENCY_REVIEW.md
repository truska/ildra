# External Asset and Dependency Review

Owner: David Cunningham (`info@truska.com`)

Next quarterly review: 15 December 2026

Cadence: review this inventory once per quarter and before any production release that changes a listed integration, its CSP allowlist, credentials, or payment flow. Record the review date and findings in the release notes or a Dev Task.

| Dependency / asset | Purpose | Source or configuration | Review focus |
| --- | --- | --- | --- |
| Bootstrap 5.3.2 | Public and admin UI CSS/JavaScript | `cdn.jsdelivr.net` | Pin version, review release/security notices, confirm CSP source remains required. |
| Font Awesome | Site and admin icons | `cdnjs.cloudflare.com`, `kit.fontawesome.com` | Review version/kit ownership and CSP sources. |
| Google Fonts (Manrope) | Web font delivery | `fonts.googleapis.com`, `fonts.gstatic.com` | Confirm font is still required and only the listed origins are allowed. |
| TinyMCE 6.8.6 (MIT) | Shared rich-text editor | Local `assets/tinymce/6.8.6/` | Cloud loader/API key removed on 24 November 2026. Version 6 is out of security support; plan a supported editor upgrade with licence review. Retain server-side sanitiser coverage. |
| Truska SMTP / bounce IMAP | Existing website email delivery and bounce collection | `../private/email.php` | Review TLS, private credentials, sender identity and bounce processing. |
| Amazon SES SMTP | Optional website email delivery selected by message type | Regional SES SMTP endpoint; credentials in `../private/email.php` | Review regional identity/DKIM, SES SMTP credentials, sandbox/production status, pricing plan and bounce/complaint feedback. |
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

## Stripe billing address collection — 26 November 2026

Reviewed Stripe's Checkout Session API documentation. Basket checkout now uses
`billing_address_collection=auto` instead of `required`, allowing Stripe to
collect billing addresses only when necessary. Existing Customer contact and
buyer-address submission, payment amounts and credit/coupon calculations remain
unchanged. Customer address submission alone does not guarantee hosted billing
field prefilling; Stripe documents saved-card requirements for that behaviour.

Validation: PHP syntax and diff checks passed. No Stripe session or real payment
was created for this configuration change; hosted form behaviour remains to be
checked in Stripe test mode.

Reference: https://docs.stripe.com/api/checkout/sessions/create

## Self-hosted TinyMCE — 24 November 2026

All existing editors use the shared loader in `helpers.php`, now pointing to
`/assets/tinymce/6.8.6/tinymce.min.js`. Core, plugins, icons, theme, skins and
content CSS are committed together; no npm install or Tiny Cloud account is
required on the destination server. Deploy the entire asset directory with the
PHP change. The previous cloud API key and CSP cloud origin were removed.

Source: https://registry.npmjs.org/tinymce/-/tinymce-6.8.6.tgz
The archive was checked against the npm registry SHA-512 integrity value before
extracting runtime minified assets, fonts, package metadata and the MIT licence.
Version 6 is retained for compatibility and its existing MIT licence; it is no
longer security-supported. Current TinyMCE versions require GPL compliance or a
commercial self-hosting licence, which needs review before upgrading.
