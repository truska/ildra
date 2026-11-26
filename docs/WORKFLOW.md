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

For pages needing only Back navigation, load `views/back_navigation_styles.php`.
The public `views/header_styles.php` also includes this same file. Its
`page-back-navigation` and `page-back-link` classes own the right alignment,
spacing, grey button, back chevrons and hover/focus appearance. Reuse this
helper when applying the same navigation to other pages; change the destination
and labels rather than copying markup or adding page-specific styling.

Campaign/email pages share `admin/campaign_navigation.php` for their tab bar
and contextual Back destinations. Top-level tabs do not add a sibling Back
button; editors/details return to their parent or accepted source view. See
`EMAIL_DELIVERY.md` for scope and offline navigation/browser checks.

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
Event-Pal export and wider proposed guided setup. The six-row development
import trial completed on 17 November 2026, followed by the single-account
email/setup pilot documented in ACCOUNT_MIGRATION_PILOT.md. No migration
invitation has been sent during implementation. Membership numbers will
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

## 21. Event editor time selectors

All seven Admin → Events → Edit time fields use separate Hour and Minute
dropdowns, with minutes 00/15/30/45. Tablet layouts use two date/time columns
per row (one date/time pair), expanding to four only at the XXL/1400px
breakpoint so iPad landscape retains one pair per row. Controls use 16px text and
44px minimum touch height. The shared `admin/five_minute_times.js` recognises
`step=900` for this presentation; Settings retains its existing five-minute
controls. Named hidden inputs preserve posted values and the date/default/late
reopening scripts. Existing off-quarter values remain selected as “existing”
until changed, avoiding silent changes to saved schedules. Blank optional times
remain blank. Labels target the visible dropdowns.

Verification: `node scripts/tests/event_quarter_hour_times.cjs` checks the seven
fields, quarter-hour choices, narrow tablet columns, generated reopening/manual
overrides and form submission values. The existing five-minute control test also
passes. No event records were changed by these synthetic browser tests.

## 22. Committee organiser and Rider Notes emergency warning

Event Edit offers Committee alongside eligible individual organisers. This is a
reserved organiser choice, stored as `organiser=Committee` with no user ID. It
does not create a shared login, grant organiser access, or receive user emails.
Existing event duplication retains the label. Selecting an individual replaces
Committee and vice versa; an explicit empty selection clears the organiser.
No new schema or account records are needed.

Ride Emergency Number is optional when saving an event, including when the
selected organiser has no number. Non-empty values still undergo phone format
validation. Manual numbers and Use organiser’s number retain their existing
selection behaviour.

Opening the admin Rider Notes editor or an authorised preview checks the same
effective number used on Rider Notes. If absent/unusable, a dismissible advisory
modal says “No Emergency Number added yet” with Continue. It does not block
saving, publishing, previewing or sending, and ordinary riders do not receive
this administrative prompt. The editor warning appears on GET, not save POSTs.

Verification: `php scripts/tests/committee_organiser.php` uses temporary copies
of every database table for Committee/individual switching, optional/manual/user
numbers and invalid-number rejection. `node scripts/tests/committee_organiser.cjs`
checks the actual emergency-control script and advisory modal in Chromium. No
real event, user or phone record is changed by these tests.

## 23. Permanent deletion in shared image galleries

Event Edit, Rider Notes, Venue Images, News/Ride Report galleries and sections,
content-section images and banners offer a red, white-bin Delete control
alongside Archive.
Third-party events also offer Delete permanently for their current image. Page
Images already has permanent deletion. Asset Library retains its separate
record-only deletion policy because stored files may be linked elsewhere.

The shared admin controls live in `admin/media_image_actions.php`. Each image's
button submits an independent CSRF-protected form after the existing Bootstrap
confirmation. It does not save unrelated parent/gallery edits or trigger their
required-field validation. The confirmation explains that all generated sizes
are deleted and that unsaved changes are not saved. Success returns to the same
editor and record. The server resolves the batch from the authorised editor's
record and checks that the image belongs to that batch. Existing
`mediaBatchDeleteImage()` removes the image record and its stored size files;
Archive continues to retain files. No real uploads were deleted during testing.

Verification: `php scripts/tests/gallery_image_delete.php` uses temporary database
tables and the actual deletion functions with a synthetic `/tmp` storage root.
It checks ownership, active/archived records, generated sizes, unrelated files,
repeat deletion and CSRF. Render fixtures with
`php scripts/tests/gallery_image_delete_forms.php <private-/tmp-directory>`, then
run `node scripts/tests/gallery_image_delete.cjs <directory>` for seven editors'
actual row controls, confirmation/cancel, context and unsaved-form isolation.

## 24. Images attached directly to content records

Awards and Advertising editors now offer the same red, white-bin control for
the image without deleting the award/advert. Uploaded Awards (sm) and Advertising
(original/md/sm) files are removed once no other record in that section uses
them. Removing an award image clears both its direct upload and Library selection
so a previously selected image does not unexpectedly reappear.

Manual/Super Announcements use shared Library assets: their bin clears the
announcement's image selection and refreshes its revision, retaining its text,
publication/schedule and the Library source. Library images selected by Awards
are likewise detached rather than deleted. The Library's own record-delete
control also uses the red bin; its established stored-file retention remains.

Dev Task screenshots have the same control for Manager/Admin/Superadmin/Developer.
Removing a screenshot preserves the message and task; the message ID must belong
to the selected task. Original/lg/sm screenshot files are removed when unused.

`record_image_removal.php` handles these fixed owner types under a transaction;
admin controls use separate CSRF-protected, confirmed forms and return to the
same editor without submitting unsaved parent fields. They do not affect inline
images embedded in rich-text bodies or remove shared Library source files.
No new upload sections, schema changes or dependencies are introduced.

Verification: `php scripts/tests/record_image_removal.php <private-/tmp-directory>`
checks all owner types with temporary tables and synthetic files only, including
shared-file/Library retention, announcement tab and screenshot task scope, CSRF
and preservation of parent records. `node scripts/tests/record_image_removal.cjs
<directory>` checks icon-only controls, confirmation/cancel and isolated forms.

## 25. Independent admin menu scrolling

The desktop admin sidebar sticks to the viewport top, has viewport height
(`100dvh` with `100vh` fallback) and scrolls vertically independently of the
editor. Scroll containment prevents reaching the menu's end from scrolling the
page behind it. Existing collapsed-menu widths and the fixed mobile/iPad drawer
remain intact. Main page scrolling remains the browser's normal page scroll.

Verification: `node scripts/tests/admin_sidebar_scroll.cjs` exercises the actual
shared CSS/menu script at 1440, 1024, 768 and 390px: pinned position, independent
menu scrolling and boundaries, desktop collapse, drawer opening and Escape.

## 26. Membership display order

Admin → Memberships → Edit has a Display order field. Lower numbers appear
first; ties use name then ID. The catalogue defaults to this order and shows an
Order column. Shared membership fetches apply the saved order to
`/pages/membership`, `/memberships` and membership dropdowns, including onboarding.

The additive `membership_types.display_order` field initializes existing types
once to Adult Membership (10), Adult 66+ Membership (20), Youth (30),
Child Member (40), Supporter (50), and other types (100). Later admin edits
persist. Existing names, prices and eligibility rules are retained; the current
Youth product is named Youth 18 - 22. New types default to 100.

Verified create/update persistence, invalid order rejection and public ordering
against a connection-local temporary copy of membership_types.

No manual SQL migration is needed on live: after deploying the code, opening
Admin → Memberships creates and initializes the column automatically using the
application database account. Later changes to display order are managed there.

## 27. Reapply event schedule defaults

Event Edit → Date & Time offers **Apply date defaults**. It keeps the entered
start date and replaces start/end times, end date, member/non-member opening,
normal closing, late reopening and late closing with current Settings rules.
This explicitly replaces manual schedule overrides; review and Save to persist.
Ordinary saved-event date changes still preserve existing schedule values.
Late reopening uses elapsed hours in Europe/London, including DST transitions.
Copy Event already uses the shared server date defaults for its new date;
this button is only needed for later rescheduling or explicitly resetting a schedule.

Verification: `node scripts/tests/event_quarter_hour_times.cjs` now also exercises
the actual editor reset script with saved overrides, all posted schedule values,
selector synchronisation and a UK daylight-saving reopening. Existing PHP
late-entry reopening checks pass. No real event records were changed.

## 28. Minimum rider age on person saves

A supplied person date of birth must make the rider at least five today in
Europe/London. Younger dates block saving with “All Riders need to be 5 years
old or over.” Blank DOB remains allowed except where existing Junior rules
require it. This applies to account person create/edit, administrator person
edits and new people added through membership purchase. Horse DOB rules and
season-based Junior/Senior classification are unchanged.

Account and admin person forms show DOB warnings immediately above the field,
with an accessible red border; failed account saves retain entered values.
Verification: `php scripts/tests/rider_minimum_age.php` checks birthday/leap-day
boundaries and actual create/edit rejection using temporary database tables only.


## 29. Settings audit history

Tech → Audit Log shows existing admin audit actions plus new Settings changes.
It is read-only and restricted to Superadmin/Developer, with sortable columns,
header-row search/select/date filters and pagination. All Settings tabs,
purchasing controls, footer links and Live Email Settings saves capture the
actual changed fields with old/new values and actor identity. Unchanged saves
add no entry; credentials are redacted. Earlier settings history is unavailable.
See [the audit plan](plans/AUDIT_LOGGING_PLAN.md) for current coverage and limits.
Verification: `php scripts/tests/admin_settings_audit.php` uses synthetic data
in temporary tables only; no real settings, emails or accounts are changed.


## 30. People and Horses archives

Admin → Archives appends People and Horses after Venues. Existing
`is_archived` flags supply the lists; records also remain visible through the
existing People/Horses status filters. No data/schema migration is required.
Admin, Superadmin and Developer can restore or permanently delete archived
records. Manager retains existing operational editors but has no archive-purge
access. Restore requires an active managing login. Purge requires typing exactly
`DELETE`, rechecks the archived state and dependencies inside its transaction,
and removes only the selected Person/Horse. It never removes a login account.
System horse 1 and the protected account holder's matching Person are protected.

Purge is blocked by relational references (including migration enrolments,
retained backups, memberships/logbooks, results, sharing and email recipients),
sharing requests, media containers, and nested ID references in persisted
baskets, booking metadata, finance/coupon metadata and campaign merge data.
Withdrawn bookings and revoked shares still count. Malformed JSON in those
sources blocks deletion until it can be checked. Blocking reasons appear beside
the disabled delete button and are rechecked server-side. Linked history is not
silently cascaded or detached. Historical notes and audit snapshots are retained;
successful actions write the shared audit log after the record transaction.

People records and login users are separate. An incorrectly registered login
email must be handled through Admin → Users; deleting a Person does not free up
that login email. No real people, horses or logins were deleted during development.

Verification: `php scripts/tests/subject_archives.php <private-/tmp-directory>`
uses all-table temporary copies and synthetic records. Run
`node scripts/tests/subject_archives.cjs <directory>` for actual archive form
controls, Cancel, exact typed confirmation, CSRF/record targets and restore.


## 31. Combined public account dashboard

`/account` now defaults to three base sections: People, Horses and Recent
Purchases. Show Memberships below People and Show Logbooks below Horses reveal
the corresponding histories; both start collapsed and independently toggle
between Show and Hide. Empty histories keep their existing guidance. Existing add/edit/archive, shared-record controls, membership/logbook
purchase and renewal actions are reused. People and Horses no longer have
separate navigation buttons. The Shares navigation button is hidden; its route,
request handlers and stored shares are retained. Admin remains visible only to
roles that already had admin access. Login/security settings remain under the
header's My Account link, and external-recognition links remain available.

Old `view=people` and `view=horses` URLs render the combined dashboard. Horse
links without an editor selection scroll to Your horses. Person/horse edit
parameters still open their respective existing modal; both add forms remain
available on the same page. People and Horse Information buttons keep separate
introductory content. Empty Horses does not replace or trigger the People intro.
No accounts, memberships, logbooks, purchases or share permissions are changed by
this layout update.

Verification: `php scripts/tests/account_dashboard.php <private-/tmp-directory>`
renders the actual account body using all-table temporary copies, with paid and
unpaid synthetic accounts, legacy links, editors, role checks and empty histories.
`node scripts/tests/account_dashboard.cjs <directory>` checks the resulting pages
at 1440px and 390px and exercises both add/edit modals and independent history
collapse/expand controls with real Bootstrap.
The browser check expects the site's pinned Bootstrap 5.3.2 files at
`/tmp/ildra-bootstrap-5.3.2.bundle.min.js` and
`/tmp/ildra-bootstrap-5.3.2.min.css`, downloaded from the same jsDelivr URLs used
by the site; other browser network requests are blocked.


After onboarding Continue, `/account` opens a one-time account welcome modal using
Admin → Onboarding → Account welcome wording (the existing
`onboarding_copy_thanks` key). A server-side marker binds it to the completed
account and is consumed once; normal visits, refreshes and query parameters do
not trigger it. Other automatic account prompts are deferred for that arrival.
The single Password & user settings form has only Continue, which saves the
password, identity and completion atomically. There are no progress pills or
second Finish page. Purchase actions happen from the dashboard.


## 32. Email-first riders added from event entry

The ride-entry Rider dropdown ends with Another Person, including for accounts
with no saved People. It opens an email-first modal in place of the old Manage
People link. Existing active People are reused. An existing login is matched to
its canonical owned rider by email or unique account-holder name, or gets a
minimal Person owned by that login if none exists. Existing riders managed by
another account are automatically linked with the existing select-only sharing
permission, without approval, verification or an email. Ownership is retained.
This automatic entry-linking rule was explicitly requested on 26 November 2026.
Shared DOB, phone, address, postcode and emergency-contact fields remain masked
by the established shared-person fetch helpers. The broader People presentation
and horse-selection changes are separate follow-up work.

Unmatched emails open the complete new-rider form. First/surname, email, phone,
address, postcode, Junior/Senior and emergency name/phone are required; juniors
also require DOB. The shared minimum age of five and classification rules apply.
New riders belong to the signed-in account and are not opted into marketing.
Both submission steps recheck matches under an email-scoped database lock;
repeated or stale submissions reuse the canonical rider rather than duplicating
it. Multiple matches, archived/unavailable records and previously revoked access
require review instead of guessing or silently overriding a revocation.

`entry_person.php` requires an authenticated active account, shared CSRF checks,
POST and a session rate limit. It returns status/field errors and the Person ID,
not another account's private details. Successful selection is audited. No schema
migration or outgoing email is introduced. `entry_people.php` prepares schema
before the transaction and performs no transactional DDL.

After save/link, the browser refreshes only the event's rider choices and current
membership/recognition eligibility data. The rider is selected in place, horse
and other form fields remain intact, and compatible class choices are retained.
Server entry eligibility and price checks remain authoritative. The compact
rider modal uses a viewport-bounded flex layout with an independently scrolling
body and fixed header/footer, keeping action buttons visible on phone and
landscape screens even when field warnings expand the form. Field warnings
appear above the controls with accessible red borders; Cancel preserves the
previous rider and does not submit anything.

Verification: `php scripts/tests/entry_people.php <private-/tmp-directory>` uses
synthetic records in all-table temporary copies and renders actual event pages.
`node scripts/tests/entry_people.cjs <directory>` checks the modal and actual
entry scripts at 1440px/390px with mocked network responses. It uses the same
private pinned Bootstrap files described for the account dashboard browser test.
No real riders were created/linked, bookings made or emails sent during testing.


## 33. Shared postcode character formatting

Postcode inputs use uppercase ASCII letters, digits and spaces only. Lowercase
is converted and punctuation/special characters are removed on typing, paste,
blur and submit; leading zeros and internal spaces are retained. This is a
character rule rather than country-specific postcode validation. Existing stored
records are not bulk-edited; an edited record is normalized when saved.

`postcodes.php` supplies the shared normalization and recursive postcode-field
handling. Bootstrap applies it to POST data after CSRF validation, covering
public/admin forms and nested/prefixed postcode fields. Person, venue, Settings,
legacy migration detail and email-first rider helpers also normalize programmatic
saves, and Stripe Customer address mapping uses the same postcode format.

The shared HTML-response callback preserves existing CSRF form protection and
loads `assets/postcodes.js` once for full HTML pages, including administrative
pages. It leaves non-HTML/API responses alone. The formatter recognizes postcode,
postal_code and prefixed/nested names, handles newly inserted controls, and also
recognizes person postcode prefills. Other fields are unchanged.

Verification: `php scripts/tests/postcodes.php` checks shared PHP formatting,
nested fields, unrelated values, asset/CSRF injection and subdirectory/non-HTML
handling without database writes. `node scripts/tests/postcodes.cjs` checks
actual typing/pasting, dynamic inputs, spaces/zeros and submit formatting in
Chromium. The email-first rider temporary-table suite also checks canonical
postcode storage. No real address records were changed by these tests.

## 34. Event horse selection and automatic links

Event entry uses canonical horses with a logbook covering the event year (the
existing annual-product rules), or approved external horse recognition valid on
the event date. Archived horses and horses belonging to archived accounts are
excluded. Ordinary non-members automatically use the system Not Registered
horse (ID 1), with no horse-name input. Members with ride-entry benefits and
verified externally recognised riders choose an eligible horse; existing
class, pricing and competitive eligibility checks still apply.

The selector lists horses owned by the selected Person's managing account,
then its linked horses and horses already remembered by the signed-in account,
then a horizontal divider and all other eligible horses. Members now have an
on-by-default switch beside Horse: on excludes Not Registered; off forces the
system Not Registered horse and disables the dropdown. Verified externally
recognised non-members retain the separate Not Registered option between
dividers. CTR/ER still require a recognised horse. Only IDs, names and
eligibility reach the public catalogue; passport and owner contact information
are not exposed. There is no free-text registration or horse duplication here.

`entry_horses.php` provides the catalogue, qualification and linking helpers.
`assets/entry_horses.js` posts a selected other horse to the authenticated,
CSRF-protected `entry_horse.php` endpoint. It creates an approved select-only
link to the signed-in account, without owner approval or ownership transfer,
then moves that horse above the divider. Existing own/linked horses need no new
link. A real event POST also revalidates and creates a missing link before adding
the entry, so ignoring JavaScript cannot bypass eligibility or canonical names.
Revoked links are not silently reinstated. New links are audited.

Verification: `php scripts/tests/entry_horses.php <private-/tmp-directory>`
uses the existing all-table temporary-copy harness to test grouping, event-year
logbooks, member/nonmember/external eligibility, idempotent linking, ownership,
minimal exported fields and archived/expired/revoked guards. It also exercises
the actual event POST controller with forged names, an ordinary nonmember and
an expired logbook. No actual customer records or emails are changed.
`node scripts/tests/entry_horses.cjs <directory>` uses the resulting event-page
fixture at desktop/mobile widths to check ordering, divider, CSRF link request,
remembered selection and rider switching. The Another Person browser suite
also passes with the new horse selector enabled.

## 35. Missing rider contact information at event entry

On Enter Event, the existing confirmation modal now covers rider phone,
emergency contact name and emergency contact phone. It appears only when a
canonical Person record is missing one of those fields and the entrant has
supplied a nonblank value for it. The modal lists precisely those missing fields;
a complete linked Person does not trigger a prompt just because shared prefills
mask their contact details.

`entryPeopleWithContactGaps()` adds boolean missing-field flags to accessible
riders, without revealing linked contact values. With explicit
`save_rider_contact=1` consent, `entryUpdateRiderContact()` fills only currently
blank fields on that same canonical Person record, owned or approved-linked.
Existing values are never overwritten, even if they changed since page load;
blank input does not erase data. No leaves the profile untouched and uses the
entry details only. Changing rider/contact inputs resets the previous consent.
The SQL update rechecks active Person and ownership/approved nonrevoked link;
successful updates are audited as `entries.rider_contact_updated`. Email and
ownership are unchanged.

Verification: `php scripts/tests/entry_contact.php <private-/tmp-directory>`
checks canonical flags, masked linked data, consent, gap-only updates and
revoked/unrelated/archived guards against temporary table copies. The matching
`node scripts/tests/entry_contact.cjs <directory>` checks exact missing-field
wording, Yes/No single submission and complete-linked-record skip at desktop
and mobile widths, with network writes blocked. No real contact records changed.

## 36. Shared cancellation policy in Settings

Cancellation Policy is edited at Settings → Events → Cancellation Policy,
using `cancellation_policy_html`. Rider Notes includes this setting below its
general footer, and the two-line Cancellation Policy button to the left of
Total Entry Fee opens `views/cancellation_policy_modal.php` with the same text.
The button does not submit the entry form; the popup scrolls for long content.
The general Rider Notes footer remains separately editable in the Rider Notes
tab. Preview shortcuts link to the relevant editor for each section.

The existing saved policy wording was moved unchanged out of
`ride_notes_footer_html` on development, preserving its other text. Before-state
backup: `private/import-workspace-backups/cancellation-policy-20261126-155556-da997d82.json`
(mode 600). `getSiteSettings()` also separates an older combined footer on read
if the dedicated key has not yet been saved, preserving custom wording and any
later heading sections without writing to the database. Subsequent Settings saves
persist the central values. Explicitly blank policy text remains blank.

Verification: `php scripts/tests/cancellation_policy.php <private-/tmp-directory>`
uses temporary tables to check legacy/custom splitting, text preservation,
idempotence, explicit blanks, audited Settings saves, actual event/Rider Notes
rendering and an edit reflected in both locations exactly once. Desktop/mobile
fixture checks verified the popup button placement and open/close behaviour.


## 37. Developer/Superadmin onboarding restart controls

Development migration controls accept active Developer and Superadmin operators,
with database-backed role verification and impersonation denial. Migration rows
include the recipient role so both roles receive the extra restart button. Own
restart opens `/migrate.php`; another privileged account's restart is scheduled
for its next verified login. Cross-account immediate restart remains rejected.
Existing data, confirmation, unique Person matching, auditing and environment
checks are retained. Destructive import-workspace authorization is unchanged.

`php scripts/tests/per_user_onboarding_reset.php <private-/tmp-directory>` now
also checks unprotected Developers, Superadmins, self/other scheduling, rendered
button behaviour, forged/ordinary roles, impersonation and the separate protected
import-workspace restriction, using temporary tables only. The offline migration
rollout suite also passes. No actual account onboarding was reset.


## 38. Member horse-selection switch

Members with active ride-entry benefits have an accessible, on-by-default switch
beside Horse. On lists own/linked horses, a divider when needed, and other eligible
horses, without Not Registered. Off disables the dropdown and forces canonical
Not Registered (ID 1), clearing the horse-required warning. Turning on again
restores the prior eligible horse if available. A newly selected rider resets
the switch to on. Ordinary non-members retain automatic Not Registered;
externally recognised non-members retain their previous dropdown behaviour.

The hidden `horse_selection_mode` carries the member switch choice. Event POST
forces ID 1 for a member's off selection and rejects ID 1 in registered mode;
posted free-text names are still ignored. CTR/ER continue to require a recognised
horse. Existing horse-link creation does not run for Not Registered.

The temporary-table horse suite checks canonical off-mode handling, registered
mode rejection and competitive eligibility. Desktop/mobile browser checks cover
switch visibility/default, no Not Registered in the member list, divider/order,
disabled off state, no stale warning, restored selection and rider switching.


## 39. Duplicate event entries warn rather than block

Historical behaviour, superseded for riders by section 41 below. Horse reuse
remains advisory.

Repeat entries no longer consume a rider's member-rate eligibility for that
event. Active membership/class eligibility, competitive horse rules and capacity
checks still apply. `entry_warnings.php` collects canonical rider/horse IDs from
non-withdrawn saved entries and this session's basket for the same event.
Event-page data exports only matching accessible rider and catalogue horse IDs.

The existing notification area now shows amber advisory warnings for an already
entered rider and/or horse, without disabling member pricing or Enter Event.
The warnings update with rider/horse selection and after Another Person refresh.
The shared Not Registered horse (ID 1), withdrawn entries and other events do
not produce horse duplicate warnings. No new schema or booking writes occur
merely from viewing/checking the form.

`php scripts/tests/entry_duplicates.php <private-/tmp-directory>` checks saved
and basket matches, exclusions and successful repeat member-price event POSTs
using temporary tables. The matching `.cjs` browser test checks both warnings,
selectable member pricing, enabled submission, placeholder exclusion and stale
warning clearing at desktop/mobile widths. No actual entries or emails created.


## 40. Entry and account changes wrap-up — 26 November 2026

The user reviewed the entry changes with a second tester and approved this batch
for commit. It includes email-first rider creation/linking, canonical eligible
horse selection and remembering, the member horse switch, missing-contact-only
consent updates, nonblocking duplicate warnings, postcode formatting, the event
summary bar and shared cancellation policy, Developer/Superadmin migration
restart controls, account toggle labels and shared header navigation shortcuts.
The temporary italic horse-switch wording remains intentionally provisional.

Final regression checks cover PHP/JavaScript syntax, temporary-table suites for
riders, horses, contact consent, duplicates, policy, onboarding resets and the
account dashboard, plus desktop/mobile browser suites for entry and account
behaviour. Policy and test-logbook database adjustments are documented separately
in this file and IMPORT_WORKSPACE.md; private backups are outside Git. This
wrap-up does not deploy or send invitations.


## 41. Restore one rider entry per event — 26 November 2026

At the user's revised instruction, an existing non-withdrawn saved entry or an
entry in the current basket prevents the same canonical Person entering that
event again, irrespective of class/member rate or operator role. Their Rider
option is disabled and ends with `(already entered)`. Submitted duplicates are
rejected server-side; the exact message is **This rider is already entered for
this event.** The rider notice sits below Rider selection. Client submission
also checks the duplicate flag, and Another Person refresh cannot select an
already-entered rider. Withdrawn entries and other events do not block re-entry.

Horse duplication remains an advisory notice below Horse selection; different
riders can select the same eligible horse. The system Not Registered horse does
not produce a horse warning. Membership/rate eligibility and capacity checks
remain separate from the rider duplicate rule.

Updated temporary-table/browser duplicate suites verify saved and basket blocks,
unchanged basket on rejection, exact text, disabled/labelled options, stale-form
submission rejection, email-first reuse, withdrawn rider re-entry and another
rider using the same horse at desktop/mobile widths. No actual entries changed.

## 42. UK source-copy review — 26 November 2026

Reviewed hard-coded application copy across 228 PHP, JavaScript and HTML files,
including public/admin labels, help text, placeholders, buttons, validation and
email messages, inline scripts and HTTP error pages. A local Aspell en_GB scan
supplied spelling candidates, followed by contextual grammar review. Third-party
editor/node dependencies, generated statistics, documentation and test fixtures
were excluded from the copy scan; technical identifiers, API/status values and
proper names were not respelled.

Corrected 30 source-text occurrences across 18 application/error-page files,
with three corresponding test-expectation updates. Examples include Catalogue,
Unauthorised, fulfil, log in/check out as verbs, missing articles and pronouns,
subject/verb agreement, clearer event/venue help text and the minimum-age
validation message. Business rules, field names, routes and saved wording are
unchanged. The exact duplicate-rider notice and intentionally provisional
horse-toggle wording were retained.

Validation: all 290 project PHP/JavaScript files passed syntax checks; offline
five-year age boundaries and the existing desktop/mobile Another Person modal
checks passed with the revised message. The dictionary scan was repeated after
corrections. No database content was read or changed during this copy review,
and no deployment or email send was performed.
