# Server Workflow Standard

This file is the master setup standard for new sites and workspaces on this server.

The goal is simple:

- every site should open the same way
- every workspace should have the same structure
- Codex and terminal tools should work without repeated sandbox workarounds
- dev and live paths should be easy to understand

## 1. Preferred Directory Layout

For each site, keep the same shape:

- `.../web` for the site code
- `.../private` for private config and secrets
- `.../log` for logs

If a VS Code workspace file is used, it should include:

- `web`
- `../private`
- `../log`

Example:

```json
{
  "folders": [
    { "name": "web", "path": "." },
    { "name": "private", "path": "../private" },
    { "name": "log", "path": "../log" }
  ]
}
```

## 2. Git Standard

Every dev site must have a normal readable `.git` directory inside the site root.

Required:

- `.git` must exist in `web/.git`
- it should be a normal Git directory or a valid worktree pointer
- the Codex workspace path must be the real dev checkout, not an awkward indirection

Avoid:

- hidden symlink chains
- mixed live/dev worktrees with unusual ownership
- setups where `.git` is readable only outside the normal dev path

## 3. Permissions Standard

The most important consistency rule:

- the site root must be readable enough for dev tools to inspect `.git`

Recommended:

- site root directory: `750`
- `.git` directory: `755`
- normal files: `644`
- executable scripts only where needed: `755`

In practice:

- owner should be the site user
- group should be the shared site group
- Codex/sandbox-safe tooling must be able to traverse and read the repo metadata

Avoid using a site root permission like `710` when the workspace is expected to be used by tooling. That is what causes the repeated `.git` sandbox failures.

## 4. Ownership Standard

Use one clear ownership model per site.

Preferred:

- site files owned by the site user and site group
- avoid mixing `root`, `webX`, and other owners inside the working tree unless there is a specific reason

If ownership is mixed, tools behave unpredictably and setup drifts over time.

## 5. Config Standard

Each site should have:

- `config.php`
- `config.example.php`
- private overrides in `../private` where needed

Secrets should not be hardcoded in multiple places unless there is a deliberate operational reason.

## 6. Workspace Standard

Each site should include one workspace file named after the dev domain, for example:

- `dev-example.witecanvas.com.code-workspace`

It should:

- open `web`, `private`, and `log`
- set a clear window title
- optionally set colour customisations for environment clarity

## 7. New Site Checklist

When setting up a new site:

1. Create the standard folder structure:
   `web`, `private`, `log`
2. Create or clone a proper Git working copy into `web`
3. Set ownership consistently
4. Set directory permissions so the workspace root is readable by dev tooling
5. Add the `.code-workspace` file
6. Add `config.example.php`
7. Confirm these commands work without escalation:
   `pwd`
   `ls`
   `rg --files`
   `git status`
   `php -l somefile.php`
8. Only after that, start coding work

## 8. Existing Site Repair Checklist

If a site behaves differently from the others, check these first:

- is `web/.git` present and readable?
- is `web` permission too restrictive?
- is ownership mixed across the repo?
- is the Codex workspace pointing at the real dev copy?
- does `pwd` fail inside sandbox before code is even touched?

If yes, fix the environment first.

## 9. Rule Going Forward

No new site should be considered "ready" until the basic read-only tool checks pass inside the normal sandboxed workspace.

That is the acceptance test for a correct setup.

## 10. Admin tab ordering

Append new admin tabs to the end of the existing tab list unless the user explicitly requests another position. Preserve the order of established tabs.


## 11. Shared back navigation above content panels

Use `render_back_navigation($url, $label, $ariaLabel)` from `helpers.php`
above the content panel, inside the page container. For example:

```php
<?php echo render_back_navigation($navItemEventsUrl, 'Back to Events', 'Event navigation'); ?>
```

Load the existing shared `views/header_styles.php` include. Its
`page-back-navigation` and `page-back-link` classes own the right alignment,
spacing, grey button, back chevrons and hover/focus appearance. Reuse this
helper when applying the same navigation to other pages; change the destination
and labels rather than copying markup or adding page-specific styling.

## 12. Admin date displays

Use `admin_display_date()` for admin list dates (`DD/MM/YYYY`) and
`admin_display_datetime()` for timestamps (`DD/MM/YYYY HH:mm:ss GMT/BST`).
These shared helpers live in `helpers.php`. Datetimes without an explicit
timezone follow the application's Europe/London database convention; Unix
timestamps and values with UTC/offset information are converted to UK time,
including daylight-saving changes. Date-only values retain their calendar date.

Keep ISO values in database storage, native date/datetime inputs, date filter
values and chronological sort keys. Format dropdown labels and visible table
cells rather than changing the underlying values.

## 13. Event archiving and hiding

The Archive action in Events requires the exact confirmation text `DELETE`.
The existing `events.delete` permission controls archiving and restoring.
`events.archive_state` is separate from the publication status:

- `active`: normal event lists.
- `archived`: no booking items, rider notes or linked news/report records;
  retained under Admin → Archives → Events for later deletion work.
- `hidden`: related records exist, including withdrawn entries and draft
  notes/reports; retained under Events → Hidden with Restore As Draft only.

Archiving never deletes related records. Both archive states set the event
publication status to draft. Restore clears the archive state and keeps the
event as a draft, with its related records intact. Public event lists and
linked event/notes/report pages exclude archived or hidden parents.
Permanent event purge is not enabled by this change.

The additive column is set up automatically by the shared event helpers;
`migrations/2026-11-13-event-archives.sql` provides the equivalent SQL.
Schema setup must finish before the archive transaction begins.

Verification: `php scripts/tests/event_archives.php` checks isolated archive
routing and restoration; `node scripts/tests/archives_confirmation.cjs --events`
checks the typed modal confirmation using a browser fixture.

## 14. Venue archiving and hiding

Venues has Current and Hidden tabs. Archive requires typing exactly
`DELETE`. Any event referencing `venues.id` through `events.venue_id`
(including past, draft, hidden and archived events) routes the venue to
Hidden; an unlinked venue routes to Admin → Archives → Venues. Images and
existing event links are retained.

Hidden venues offer Restore As Draft only. Restoring either lifecycle state
sets `archive_state=active` and `is_draft=1`; Current shows a Draft badge.
Reviewing and saving the venue clears the draft flag. New event selectors
exclude hidden, archived and draft venues, while the event editor retains
its existing venue selection to preserve established links. Existing events
continue to display their venue details.

The additive fields are set up by the venue helpers; equivalent SQL is in
`migrations/2026-11-13-venue-archives.sql`. Permanent venue purge remains
future work. Verification: `php scripts/tests/venue_archives.php` and
`node scripts/tests/archives_confirmation.cjs --venues`.

Client sign-off is pending for the page, announcement, event and venue
archive/delete flows. Pause further lifecycle changes until that review.
Venue Current/Hidden tab badges show totals before table filters.

## 15. Entry Hub availability

Admin → Settings → Global → Entry Hub controls the shared-link entry view.
The entry_hub_enabled setting defaults to 0. Both entry_hub.php (code links)
and booking_entry.php (legacy numeric redirects) return a generic 404 before
entry lookup while disabled, for all visitors including administrators.
Bookings hides View Details and does not generate hub GUIDs while disabled.

Enabling the setting restores the existing link-based access, including
contact information; it does not add owner authentication to the hub.
Disabled responses carry no-store/private cache and noindex headers.
Verification: python3 scripts/tests/entry_hub_access.py exercises both
endpoint guards with synthetic settings and no database or customer data.

## 16. Announcement Close behaviour

Close dismisses normal, Super and automatic event announcements for the
current browser tab session using sessionStorage. It survives navigation and
reloads, including Force Show notices, and is separate from Allow Hide.
Super announcement Escape uses the same Close behaviour. Normal notices
retain Collapse as a separate action and now also provide Close; automatic
event notices also provide Close.

Session dismissal is keyed to the individual announcement token, so a new
or revised notice can appear. Hide for N days continues to use localStorage,
the configured duration and existing cross-tab synchronisation. No days-based
preference is created by Close. A fresh tab session can show the notice again.

Verification: node scripts/tests/announcement_hiding.cjs and
php scripts/test_announcements.php.

## 17. Menu editor and admin placeholders

Menu uses separate full-width list and create/edit views. Back, Cancel and
successful saves preserve list filters. Storage Folders remains a technical
utility with its existing side-by-side layout by agreement.

Live Email Settings starts under Tech when first created, but subsequent
Parent section changes in Menu persist; setup no longer moves it back.
Its endpoint's existing Superadmin/Developer access restriction remains.

Admin placeholders match public forms: lighter text, invisible while focused,
and visible again when the empty field loses focus. Saved values are retained.

The account migration plan in docs/plans records the agreed three-column
Event-Pal export and proposed guided setup. No importer, activation workflow,
data import or invitation send has been performed. Membership numbers will
be newly allocated when people join for 2027.

## 18. Admin menu dividers

Menu → Add Menu Item supports Link, Section heading and Divider. Dividers
use the existing Parent section, Order, role visibility and Visible in menu
controls. The optional label is for the editor only; destination and icon do
not apply. Dividers cannot have children or be selected as a parent.
The sidebar removes leading, trailing and consecutive dividers within each
level after applying visibility and parent role restrictions.

The additive `admin_menu_items.item_type` column is created automatically by
`ensureAdminMenuTable()`. Existing links and sections retain their behaviour.
The public website menu continues to use its existing divider-below setting.
Verification: `php scripts/tests/admin_menu_dividers.php`.

## 19. Form validation feedback

For new or updated forms, show each missing/incorrect-field warning immediately
above its field and give the affected control a red border. Preserve entered
values after a failed save. Associate warnings using aria-invalid and
aria-describedby. General failures without a specific field can remain in the
page alerts. This convention is implemented first on Admin → Email → Email
Settings, including its test recipient; other existing pages are unchanged.

## 20. User password tools and recovery confirmation

Admin → Users has a separate Reset password button on eligible user rows. It
opens a modal with the account email, a new password field, Show/Hide and Reset
password. The old password is not required.
Admin can reset active ordinary User accounts; Superadmin/Developer retain access
to other unprotected active accounts. Manager has no password-reset access.
Protected accounts use their own password-change or recovery flow. Server checks
mirror visibility; password changes use the shared session/token revocation helper
and existing audit event without recording the password. Passwords must be 8–72
characters; errors reopen the editor with a warning above the field and a red
border. Password values are never echoed back and are cleared/remasked on close.

Successful Forgot Password requests redirect to a dedicated Check your email
screen (`account?auth=reset-sent`) with the shared generic Bootstrap success
alert. It explains that the one-hour, single-use emailed link sets a new password
without the old one; it does not redirect to Email Sign In. Failed requests remain
on the request form. Reset links render the recovery form even when an ordinary
account session already exists, rather than showing My Account's change-password
form. New password and confirmation have Show/Hide controls, 8–72 character
validation and accessible field errors. Reset POST carries its token explicitly
to `/account`; validation failures retain the recovery form. Recovery screens use
no-store and no-referrer headers. No expiry or delivery setting changed.

Preparation exceptions and failed submissions now write a private PHP diagnostic
without passwords, bearer tokens, email bodies or exception messages. Public
responses remain the same for registered and unregistered addresses. The user
authorized one real recovery request on 20 November: its email was accepted by
SES and recorded in the email log; opening its link confirmed the dedicated form
without changing the account password. The earlier missing request could not be
reproduced; no recent password-reset throttle/token evidence was present before
that controlled request.

Verification: PHP syntax checks, authentication-email-origin fixtures,
password/session-revocation temporary-table tests and a reveal-control script
check passed. No real account passwords were changed; only the explicitly authorized recovery
email was sent during the follow-up live check.

Password recovery transaction correction — 20 November 2026: token consumption
must not run `CREATE TABLE IF NOT EXISTS` inside the reset transaction. Normal
MySQL DDL implicitly commits even when the table already exists, unlike the
temporary-table DDL used in the original tests. The reset handler prepares tables
before starting its transaction; token consumption now only prepares its table
when called outside a transaction. This fixes the generic reset failure after a
potentially already-applied password change/token revocation. The revocation test
now rejects transactional schema operations so its temporary fixtures cannot
mask this production behaviour. Reset exceptions also emit a private class/code
diagnostic without credentials or tokens.
