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
