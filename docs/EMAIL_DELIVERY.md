# Email delivery: Truska and Amazon SES

The website sends email through either the existing Truska SMTP connection or Amazon Simple Email Service (SES), the AWS email sending product. It does not require Agent Toolkit, an AWS CLI login profile, a new server, or an AWS SDK.

## Development setup status — updated 15 November 2026

The user reports that the email system is now fully verified. The pre-commit review found no further application code changes needed; offline email-delivery and authentication-origin tests and PHP syntax checks passed. This review did not independently query AWS or send a new test email. The user approved committing the reusable email changes on 15 November; no push or deployment has been performed.

- SES region: `eu-north-1` (Stockholm), using IAM SMTP credentials.
- Verified email identity / SES From address: `info@enduranceridingireland.com`.
- The user reported full email-system verification on 15 November. Individual AWS domain/DKIM, production-access and feedback settings were not independently rechecked during the pre-commit review.
- Credentials are installed only in the development environment's private email configuration. Never include private credentials, downloaded credential CSVs or private configuration backups in Git.
- The SMTP user required an IAM policy granting `ses:SendRawEmail` for the verified email identity. The user added it, and SES subsequently accepted the end-to-end test.
- Successful test: email log **#132**, subject **ILDRA SES end-to-end test BAB8CC6A**. SMTP acceptance is confirmed; inbox receipt has not yet been confirmed by the user.
- Development default delivery is **Amazon SES**, with no per-type overrides. The global testing redirect is enabled to **info@enduranceridingireland.com**, removing original CC/BCC recipients. Keep this redirect enabled until the user explicitly authorizes changing it.
- Truska remains configured as the second delivery choice. No live/staging configuration or routing has been changed.
- Offline email routing, settings-template and authentication-origin checks passed. No production delivery/DNS/bounce-feedback validation has been completed.

Before rollout, check the destination’s domain/DKIM status, SES production-access status, selected pricing plan and bounce/complaint handling. Confirm its intended sender and per-type routes, and agree when to remove the redirect. These are operational settings rather than required application code changes. Commit reusable source/documentation changes on development and push only as authorized. Preserve unrelated pre-existing changes in `docs/README.md`.

## Settings and routes

Scheduled campaigns need an absolute public URL under **Settings → Campaign Email → Public base URL** (for live, `https://enduranceridingireland.com`). Cron has no HTTP request hostname; a blank setting also requires a hostname from the private email environment configuration. Without either, older code generated relative ride, calendar and unsubscribe links. The development safeguard now rejects missing base URLs and configured URLs without an HTTP/HTTPS scheme before generating campaign content. Deploy this source correction through development first; check the destination's setting before its next campaign. Previously delivered emails cannot be repaired by changing the setting.

Superadmin/developer users can manage **Admin → Email → Email Settings**:

- Existing Truska settings and credentials retain their existing resolution rules.
- Amazon SES has a separate region, From address and From name. The default region is `eu-north-1` (Stockholm).
- Choose a default sending system, then optional overrides for each listed email type. Unlisted/future types use the default.
- Settings test emails have an explicit system selector and use saved settings. Campaign tests have their own route selections.
- Testing redirect applies to both systems, clearing original CC/BCC recipients.
- The email log identifies SES deliveries as `ses_smtp`. Existing `smtp` records retain their historical provider label.

Until routes are changed, all messages continue using the existing connection. Selecting SES requires a syntactically valid sender and private credentials; this is a local configuration check, not confirmation that SES has verified the identity or granted production access. A failed SES delivery is logged and does not automatically retry on Truska.

Routes are stored as JSON in `site_settings.email_delivery_routes`, keyed by the message `kind` or `type`. The fallback is `email_delivery_default`. Defaults are seeded by the existing settings mechanism; no schema migration is needed.

## Connect Amazon SES

1. In the SES console, select `eu-north-1`. Check the pricing plan: à la carte sending is available, while new setups may default to Essentials. Use shared IP sending unless there is an explicit reason to purchase dedicated IPs or other extras. See [current SES pricing](https://aws.amazon.com/ses/pricing/).
2. Verify the sending domain/address in that region and publish SES's required DNS records, including DKIM. Request production access before sending to unverified recipients if the project is in the SES sandbox.
3. Create **SES SMTP credentials in that region**. These are different from AWS console credentials and CLI login profiles such as `ILDRAAWS`. Do not place credentials in chat, the database, public files, or Git.
4. Add the following `ses` sibling beside `outbound` within the appropriate environment in `../private/email.php`, preserving all existing settings:

   ```php
   'ses' => [
       'username' => '', // Populate securely with the SES SMTP username.
       'password' => '', // Populate securely with the SES SMTP password.
   ],
   ```

   Use separate environment blocks for development and live. Protect the private file while keeping it readable by PHP. The application also supports private `region`, `from_email` and `from_name` overrides in this block; if used, these take precedence over the corresponding admin fields.
5. Save the SES region, verified From address and From name in Email Settings. The SMTP endpoint is derived from the region, using port 587 and required STARTTLS. The public Reply-To remains shared with Truska.
6. With the testing redirect checked, use the SES test selector to send to an authorized test recipient. In sandbox mode that recipient must also be verified. Review the log, then choose which message types should use SES and save.

SES uses the verified From address as the SMTP envelope sender and manages its own return path. Existing Truska bounce-token headers remain useful in logs, but its IMAP bounce collector cannot collect SES bounces. Configure SES bounce/complaint feedback for the sending identity; automated SES event ingestion/suppression syncing is not provided by this change. Monitor that feedback before enabling campaign sends.

References: [SMTP credentials](https://docs.aws.amazon.com/ses/latest/dg/smtp-credentials.html), [SES regions](https://docs.aws.amazon.com/ses/latest/dg/regions.html), [sandbox and production access](https://docs.aws.amazon.com/ses/latest/dg/request-production-access.html).

## Validation and rollout

16 November follow-up: field validation warnings on Email Settings now appear above the affected controls with red borders and preserve entered values. Campaign URL generation rejects missing/invalid base URLs. Offline delivery/template and authentication-origin checks passed; `php scripts/test_campaign_urls.php` checks staging/live origins and missing cron hostnames. Destination testing is still required. On staging use `https://ildra.witecanvas.com` for Campaign Email → Public base URL until the final domain launches. After deployment, verify a controlled test's ride, calendar and unsubscribe links, its recipient/CC/BCC log, and inline settings validation. No real emails were sent during these checks.

Run `php scripts/test_email_delivery.php` and `php scripts/test_auth_email_origins.php`. Tests are offline: all transports, credentials and log writes in the delivery fixture are simulated, with no production configuration loaded or real email sent. They cover both metadata keys, each known email type, fallback and override selection, test selection, separate credentials/senders, shared Reply-To, redirects, CC/BCC, attachments, log providers, failed/missing SES configuration and settings validation/secret exclusion.

Deploy committed development source using the normal [deployment workflow](DEPLOYMENT.md); configure the destination's private SES credentials separately. Do not copy development credentials to staging/live or change reusable source there. No new upload directories or runtime dependencies are required. Verify actual SES delivery only after the relevant identity, DNS, credentials and feedback handling are configured. Roll back routing by choosing Truska; the additive settings may remain.

## Campaign navigation — 17 November 2026

Campaign lists, template selection/editing, campaign creation/editing, Account
migration, Email history/details and Email settings use the shared
`admin/campaign_navigation.php` tab bar. Existing order is preserved: Manual
campaigns, Automatic campaigns, Templates, Email history, then Account migration
for Developer/Superadmin users. Exactly one tab is active on each view.

Top-level tab pages use the tabs to switch sections. Subpages use the shared
`render_back_navigation()` grey, right-aligned Back button with double chevrons,
above their content. Campaign/template editors return to their parent section;
a template opened from a campaign returns to that campaign. The template picker
returns to Manual campaigns, and creating from a picked template returns to the
picker. Rider Notes campaigns retain their event-specific Rider Notes parent.

Email details opened from a recipient report return to that campaign. Details
opened from Email history preserve history filters, sorting and pagination.
Email settings use the same Back style and return to the originating history
view; saving settings keeps the settings screen open. Navigation return targets
are canonicalised against a small allowlist of internal admin destinations,
never arbitrary URLs. Campaign/template save redirects retain accepted origin
context. Notes opened from Account migration retain its tabs and return there;
notes opened from Users/People retain their record-management navigation.

The shared navigation flag loads `views/back_navigation_styles.php` in the
admin head only for participating pages, reusing the project's Back-button
style without loading public-site button rules.
No schema, delivery routing, account settings, approval or real email-send change
is part of this navigation update. PHP/web readability for the new helper was
verified with HTTP 200 and mode 664.

Checks: `php scripts/tests/campaign_navigation.php` verifies navigation parents,
origin preservation, role visibility, unsafe return rejection and active/Back
counts without a database. Pass a private temporary fixture directory under
`/tmp/` to also render the shared navigation for
`node scripts/tests/campaign_navigation.cjs <directory>`; Chromium checks tab
order/active state, shared Back styling and click destinations across 15 views.
`php scripts/test_email_delivery.php` and `php scripts/test_campaign_urls.php`
remain offline regression checks; no real emails are sent.


17 November follow-up: development migration, magic-login and password-reset
email links now use `https://dev-ildra.witecanvas.com:28086`. The exact development
Host authorities without a port, with `:28085`, or with `:28086` all resolve to
that fixed HTTPS origin. Arbitrary ports/hosts remain rejected; staging/live
origins are unchanged. Migration preview uses the same origin map. Existing
emails retain their original URLs; newly generated emails receive the fix.
Development's ordinary campaign base URL already uses the working HTTP proxy
`http://dev-ildra.witecanvas.com:28085` and was left unchanged.

The navigation change had accidentally loaded the full public stylesheet into
campaign admin pages, applying its global `.btn {font-weight:800}` rule. Shared
Back CSS now lives in `views/back_navigation_styles.php`; public header styles
include that file, while campaign admin and migration setup load only that
small shared file. Their Bootstrap buttons retain normal weight. Public-site
button styling is preserved. PHP, origin/delivery/URL fixtures and Chromium
navigation/setup checks passed, including normal button weight. The new shared
file is mode 664 and PHP read access returned HTTP 200. No real email was sent.


Migration recipient expansion: Account migration now offers selected accounts or
all not yet successfully emailed, with a reviewed per-recipient batch and explicit
send. The active redirect was read-only verified as `clients@truska.com`. A
`required_test_redirect` delivery guard pins each reviewed migration send to that
address in the transport settings snapshot and blocks changed/disabled redirects.
Existing To/CC/BCC redirection, original-recipient banner and email log are reused.
No redirect configuration was changed and no new real message was sent during
implementation. See ACCOUNT_MIGRATION_PILOT.md for forwarding/tester entry and
retry/resend rules. `scripts/tests/migration_batches.php` and the extended offline
email-delivery and Chromium setup fixtures passed.

## Single visible email preference (20 November 2026)

Registration, My Account, People and the admin user/person forms show **Ride Reminders and Updates**, using the existing `ride_notice_opt_in` value. Saving sets `general_email_opt_in`, `ride_notice_opt_in` and `renewal_reminder_opt_in` to the same choice. Existing divergent values are not bulk changed. Campaign types and audience selection still use their original columns.

Existing unsubscribe links for any of those three categories turn off all three preferences for matching user/person email addresses and cancel pending campaign deliveries in those categories. Essential operational messages remain separate. GET and preview requests do not change preferences.

The uncommitted onboarding update now uses the same single preference and saves all three flags together on the Person and matching account. Regression checks: `php scripts/tests/email_preferences.php` (isolated PDO fixture; no database writes or email sends).

Production invitation delivery is separately pinned by `required_migration_redirect`:
an empty target means explicitly configured direct delivery with the global
redirect disabled; a non-empty target requires that exact redirect to remain
enabled. The shared sender rechecks immediately before transport. The private
production opt-in and cutover sequence are documented in ONBOARDING_LIVE_ROLLOUT.md.

## Delivery outcome reporting

The separately committed delivery-status enhancement records SMTP acceptance,
provider IDs and submission duration, and can display signed SES delivery/bounce
feedback. See [EMAIL_DELIVERY_STATUS.md](EMAIL_DELIVERY_STATUS.md) for the
additive schema, private configuration and AWS/SNS setup. Code deployment alone
does not connect Amazon feedback; unconfigured installations continue to show
acceptance without claiming confirmed delivery. Validation used offline signature
fixtures, temporary tables, a loopback SMTP fixture and synthetic browser panels.
No real email was sent by those checks.
