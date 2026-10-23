# Session Changelog - 2026-08-05

Purpose: record the dev-site changes made in this session so they can be replayed onto dev/live deliberately, or reverted if needed.

Site: `dev-ildra.witecanvas.com`
Repo path: `/var/www/dev-ildra.witecanvas.com/web`
Resolved path: `/var/www/clients/client3/web7/web`
Site code used for email/bounce correlation: `ildra`

## Current Changed Files

Git-tracked files modified:

- `admin/_bootstrap.php`
- `admin/email.php`
- `admin/email_view.php`
- `email.php`
- `header_styles.php`
- `site.php`
- `views/header_styles.php`

Untracked file added:

- `scripts/process_bounces.php`

Private/server-side file changed outside the web root:

- `../private/email.php`

Server state changed:

- Root crontab has a bounce processor entry running every 10 minutes.

## Front-End Button Styling

Files:

- `header_styles.php`
- `views/header_styles.php`
- `site.php`

Changes:

- Added standard reusable button classes:
  - `button1` / `btn-main`: primary call-to-action.
  - `button2` / `btn-secondary-site`: secondary button.
  - `button3` / `btn-tertiary-site`: lower-attention button.
- Added button CSS variables for background, text, border, hover colour, hover transform, and subtle hover shadow.
- Kept compatibility with existing Bootstrap-like classes:
  - `.cta-btn` now follows the primary button treatment.
  - `.btn-success` follows the secondary treatment.
  - `.btn-outline-success` follows the tertiary treatment.
- Changed the homepage lower header:
  - Main hero CTA now uses `button1`.
  - `View events` changed from an `action-chip` link to a proper `button2` button.

Rollback notes:

- Revert the edits in `header_styles.php`, `views/header_styles.php`, and the two class changes in `site.php`.

## Email Private Config

File:

- `../private/email.php`

Changes:

- Added private email configuration outside the web root.
- Config supports environment selection:
  - Default/dev environment for `dev-ildra.witecanvas.com`.
  - Live environment selected for `enduranceridingireland.com` and `www.enduranceridingireland.com`.
- Dev sends through the shared `witecanvas.com` mailbox pattern.
- Live is prepared to send through the `enduranceridingireland.com` mailboxes.
- SMTP uses authenticated SMTP through `smtp.truska.net`, port `587`, TLS.
- Bounce mailbox uses `imap.truska.net`, port `143`, no TLS, because that was the working IMAP combination verified on the server.
- Site code is set to `ildra`.

Security note:

- The actual passwords are intentionally not copied into this changelog. They are in the private config file only.
- The private config file needed to be readable by the site PHP user; ownership/permissions were adjusted so PHP-FPM could read it without causing HTTP 500s.

Rollback notes:

- Remove or rename `../private/email.php`, or restore the previous private config if one existed.
- If reverting fully, also remove the bounce cron entry.

## Email Sending System

File:

- `email.php`

Changes:

- SMTP is now preferred over `PHP mail()`.
- Added private config loading from `../private/email.php`.
- Added dev/live environment resolution using host name, with CLI support through `cli_host`.
- Private SMTP settings override site settings and stale database SMTP credentials.
- SMTP username/password are no longer saved back into the database.
- Database-stored SMTP secrets are ignored when private config is present.
- Added site-coded bounce sender generation:
  - Format: `bounces+<sitecode>-<timestamp>-<token>@<domain>`
  - Current site code: `ildra`
- Added generated `Message-ID` values.
- Added extra outbound headers:
  - `Message-ID`
  - `X-CMS-Bounce-Token`
  - `X-CMS-Site`
  - `X-CMS-Log-ID`
- Email logging now creates a row before sending, then updates it to `sent` or `failed`.
- New/expanded email log metadata includes:
  - provider
  - SMTP host
  - SMTP port
  - SMTP security
  - message id
  - site code
  - bounce token
  - envelope sender
  - delivery/debug snapshot
  - SMTP error text on failure
- Added helpers to update email log rows after creation.
- Added helpers to mark rows as `bounced`.
- Added token lookup for matching bounces back to the original email log row.

Schema notes:

- No risky schema expansion was used for bounce fields.
- Correlation data is stored in `email_log.meta_json`.
- Existing `email_log.status` now supports `queued`, `sent`, `failed`, and `bounced`.

Rollback notes:

- Revert `email.php`.
- Existing `email_log` rows can remain in place; newer metadata will simply be unused by old code.

## Bounce Processor

File:

- `scripts/process_bounces.php`

Changes:

- Added a CLI worker script that:
  - Connects to the configured bounce mailbox over IMAP.
  - Scans `INBOX`.
  - Extracts bounce tokens from the returned alias first.
  - Falls back to `X-CMS-Site` and `X-CMS-Bounce-Token` headers.
  - Matches bounces to `email_log.meta_json`.
  - Marks matched log rows as `bounced`.
  - Stores bounce details/excerpt in the log row.
  - Moves matched mailbox messages into a site-specific folder.
- Processed folder pattern:
  - `INBOX.Processed.<sitecode>`
  - For this site: `INBOX.Processed.ildra`
  - Falls back to `INBOX.Processed` only if no site code is available.
- Unmatched mailbox messages are left in `INBOX` and not linked to any log row.

Cron:

```cron
*/10 * * * * cd /var/www/dev-ildra.witecanvas.com && php web/scripts/process_bounces.php >> /var/www/dev-ildra.witecanvas.com/log/bounce_processor.log 2>&1
```

Rollback notes:

- Remove `scripts/process_bounces.php`.
- Remove the cron line above from root crontab.
- The processed IMAP folders can be left alone or removed manually from the mailbox.

## Admin Email Settings

File:

- `admin/email.php`

Changes:

- Settings text updated to explain that private server config is the source of live SMTP credentials.
- Settings UI continues to display resolved SMTP username and password-present state but does not allow editing/saving SMTP secrets into the database.
- Settings save path keeps SMTP secrets empty in `site_settings`.

Troubleshooting note preserved:

- If SMTP auth fails after updating private config, inspect CMS/database-backed email settings. Old saved DB credentials can otherwise confuse diagnosis, although this implementation is designed so private config wins.

## Admin Email Log

Files:

- `admin/email.php`
- `admin/email_view.php`
- `admin/_bootstrap.php`

Changes:

- Default email log sort changed to newest ID first.
- Added visible `#`/ID column.
- Added `bounced` status display using warning styling.
- Email detail view now treats `bounced` as a valid status and labels the detail as bounce information rather than a normal error.
- Added reusable admin table styling:
  - `.admin-data-table`
  - `.admin-table-filter-row`
  - `.admin-table-filter`
  - `.admin-table-filter-actions`
- Added email log filters under the header row:
  - ID: text search
  - Email/to: select
  - Subject: text search
  - Status: select
  - Sent date/time: text search
- Filtered result counts show `filtered of total`.
- Pagination and sort links preserve current filters.

Known open follow-up:

- The filter controls are visually present and the server-side filtering query is implemented, but the controls currently require the `Filter` submit button. The next tidy-up is to add the shared JavaScript behaviour so selects submit on change and text inputs submit after a short debounce while typing.

Rollback notes:

- Revert `admin/email.php`, `admin/email_view.php`, and the table CSS block in `admin/_bootstrap.php`.

## Admin Bootstrap 500 Fix

File:

- `admin/_bootstrap.php`

Changes:

- Added `$adminManualHref` to the `global` list in `admin_layout_start()`.
- This fixed one cause of `email.php?view=settings` returning HTTP 500.

Rollback notes:

- Reverting this line may reintroduce the 500 if the template expects `$adminManualHref`.

## Validation Already Performed

Syntax checks were run cleanly during the session for changed PHP files, including:

- `admin/_bootstrap.php`
- `admin/email.php`
- `admin/email_view.php`
- `email.php`
- `scripts/process_bounces.php`

Runtime checks performed:

- `email.php?view=settings` was checked by HTTP request and returned a login redirect instead of HTTP 500 once the config/readability issues were fixed.
- Bounce worker was run manually and connected to IMAP successfully.
- Bounce worker reported zero mailbox messages at the time of the check.
- Controlled SMTP send succeeded after the SMTP password was corrected.
- User sent two further test emails successfully.
- Email log rows were confirmed for the controlled send and the two user test sends.
- Filter SQL was tested directly for an ID search and returned the expected row.
- A filtered admin log page render was generated successfully from CLI.

## Reapply Order

Recommended order for replaying these changes onto a clean dev copy:

1. Restore or reapply `email.php`.
2. Add `../private/email.php` with the correct environment credentials and `site_code = ildra`.
3. Confirm private config permissions allow the site PHP user to read it.
4. Add `scripts/process_bounces.php`.
5. Install the 10-minute cron entry.
6. Reapply admin changes in `admin/_bootstrap.php`, `admin/email.php`, and `admin/email_view.php`.
7. Reapply front-end button changes in `header_styles.php`, `views/header_styles.php`, and `site.php`.
8. Run PHP syntax checks.
9. Open `admin/email.php?view=settings` and confirm no HTTP 500.
10. Run the bounce worker once manually.
11. Send one controlled valid email.
12. Inspect `email_log.meta_json` for `site_code`, `bounce_token`, `message_id`, and `envelope_sender`.
13. Confirm unmatched mailbox messages remain unmatched.

## Current Risk / Watch Items

- The private config contains credentials and must stay outside the web root.
- The root crontab was changed manually; include it in deployment/revert steps.
- The live and dev file trees have both changed, so do not blindly overwrite either side without reviewing diffs.
- The table filter UI needs shared JavaScript activation if the desired behaviour is immediate filtering on select/change or typing.
