# Onboarding wording editor — 20 November 2026

Admin → Onboarding provides separate tabs for Password, User settings, Person
settings, Horses, Shares, Finish and Thank you. Each saves its introductory
HTML message independently using the shared TinyMCE editor. Empty content hides
the message. Existing copy is the fallback until saved; Thank you is shared by
the completed page and completion dialog. Horse introductory copy also appears
when there are no horses. Form labels, prices and operational messages remain
part of the forms.

The editor is available to Manager, Admin, Superadmin and Developer, with an
endpoint role check and shared CSRF protection. Content uses the existing
server HTML allowlist at save and display. Values use `onboarding_copy_<step>`
keys in the existing `site_settings` table; no schema migration is required.
The menu item is added under the Admin section where present, with subsequent
menu moves preserved. No account progress or invitation records are changed.

Verification: PHP syntax checks, default/blank/custom HTML sanitisation checks,
and the existing isolated `scripts/tests/account_migration.php` suite passed.

The new editor and helper are committed separately from the earlier migration
implementation. The migration view connections remain with that uncommitted
implementation; commit and deploy them together before relying on this editor
for the public setup screens in another environment.

## Refined journey and optional pages — 20 November 2026

Password and user confirmation share one page. Its Next commits the chosen
password, confirmed first/surname and unchanged login email in the same
transaction, then advances directly to Person settings. Old passwords are not
requested. Password/session revocation and invitation/owner checks remain. The
existing Password and User HTML blocks retain their stored values and are edited
together under Password & user settings; the separate User tab is removed.
Existing unfinished `user` states render the same combined page.

Admin → Onboarding → Settings provides three Yes/No fields:

- Enable Horses page (default Yes).
- Enable Shares page (default Yes).
- Show Blank Pages (default No): one setting shared by both optional pages.

Enabled pages appear when relevant records exist. With Show Blank Pages=Yes,
enabled empty pages also appear with an explicit no-horses/no-shares message.
A disabled page is skipped regardless of records or Show Blank Pages. Progress
and Back/Next use the same visible-stage rules. Finish validates included stages;
excluded stages are marked skipped, without fabricating reviews or modifying
horses/sharing permissions. Settings changes take effect on unfinished journeys;
re-enabling a required stage restores review before Finish. Completed/declined
accounts are never reopened.

Shares remains an informational stage, not a new permission-management flow.
Existing active incoming/outgoing person/horse links and pending share requests
count as content for its visibility; the page displays a count or an empty
message. It does not create, approve or revoke a share.

The existing site_settings table stores `onboarding_horses_enabled`,
`onboarding_shares_enabled` and `onboarding_show_blank_pages`. No schema migration
or account reset is needed. Welcome and Person content remain as before.

Verification: `php scripts/tests/onboarding_refinements.php <private-/tmp-dir>`
uses the existing all-table temporary-fixture harness, and optionally renders
actual admin settings and onboarding forms. Run
`node scripts/tests/onboarding_refinements.cjs <directory>` and the existing
`account_migration.cjs` for controls/forms. Existing horse-choice and membership
fixture suites also pass. No real password, account progress, share permission,
email send or purchase was changed during these checks.

## Onboarding test-data reset controls

Admin → Onboarding → Settings offers two reset buttons to the protected Developer
in the explicitly opted-in development workspace:

1. Reset data — keep users. Delete existing people, operational horses (except
   system horse 1), entries/bookings/results, sharing records, memberships,
   logbooks, credits/debits and financial records, onboarding/invitation state
   and test email/send history. Keep every user account byte-for-byte unchanged.
   Recreate one fresh Person per active user from their name/login email only;
   no previous contact details, preferences, memberships or member number remain.
2. Reset data and remove users. Apply the same cleanup, also removing all
   unprotected users. No Person records are recreated; the protected login stays
   usable, ready for a separately authorised fresh import.

Both preserve events, venues, Rider Notes, reports, pricing, uploaded images,
website content/settings, campaign/template definitions, development tasks,
private source files and historical source/backup tables. Removing users clears
optional account references and transfers required task authorship using the
existing import-workspace policy, without deleting associated content. Campaigns
return to Draft and live campaign sending is disabled. Membership numbering
restarts at 1000; primary-key sequences are not reset. No external Stripe
operation/refund, email, import or new invitation is triggered.

Each first click only builds a server-bound preview of deletion counts. The
modal requires its exact mode-specific phrase and confirmation that testing,
scheduled sends and payment/webhook processing have stopped and other browser
baskets are closed. Cancel performs no deletion. Final POST requires CSRF and a
one-use preview token. Previews expire after 15 minutes; relevant data/plan/user
changes invalidate them. Field errors retain entered confirmation values with
warnings above the controls; failed validation issues a fresh nonce without
extending the original preview lifetime.

The existing private development opt-in and protected Developer checks apply to
both preview and execution; a copied source file or Host header alone cannot
enable this on staging/live. The shared import reset runner locks/rechecks the
transactional database, writes/fsyncs a complete mode-600 schema/data backup under
`../private/import-workspace-backups/`, then applies the reset atomically. Backup
failure prevents deletion. On failure, database mutations roll back.

Implementation: `onboarding_reset.php`, `admin/onboarding_reset_panel.php`, and
an optional onboarding mode on the existing `importWorkspaceRun()` backup runner.
`importWorkspaceSnapshot()` can include retained users in the preview hashes.
The original Import workspace reset's default behaviour remains unchanged.

Verification: `php scripts/tests/onboarding_reset.php <private-/tmp-directory>`
shadows every installed table before testing either mode. It verifies retained
users/passwords, minimal Person recreation, content/image retention, finance and
onboarding cleanup, rollback, development authorisation, stale/expired previews
and completed private fixture backups (removed afterwards). Run
`node scripts/tests/onboarding_reset.cjs <directory>` for both actual modal forms,
exact phrases, stopped-testing checkbox, preview-only first clicks, Cancel and
CSRF/preview-token submission. All test submissions are intercepted. The existing
Import workspace and onboarding-refinement fixture suites also pass.

No real reset was run while implementing these controls. Running one requires
the administrator to use its explicit preview and final confirmation.

## Per-account restart for training and testing

Admin → Emails → Account Migration has Reset Onboarding beside each eligible
account with a reviewed Person match, including completed accounts. It is not
in Admin → Users. The existing protected Developer/development-workspace access
check applies to the action as well as the page. The separate per-row POST form
requires CSRF and the exact shared-modal confirmation `RESET ONBOARDING`.

Reset clears that account's progress/confirmation timestamps and horse review/
choice evidence, revokes its old onboarding links, and records an internal audit
note. It does not change its password, people, horses, sharing permissions,
bookings, paid memberships/logbooks, baskets or financial history. Other users'
onboarding state is unchanged. Archived/closed accounts are not unarchived; a
missing/ambiguous or changed Person mapping requires review first.

The stored `restart` state schedules the journey without interrupting existing
sessions (including the protected administrator's current session). The next
verified sign-in starts the combined Password & user settings page. A private
one-use fresh-authentication signal from `authBindCredentialSession()` activates
scheduled resets in bootstrap after session validation; password, authenticator,
magic-link, recovery and remembered login paths share this binding. It does not
repeatedly reset an already-started journey. No schema change is required.

A fresh invitation can also start the scheduled journey through Continue.
Existing send history is retained, so select Allow resends for an already-emailed
account. Sending still requires the normal reviewed preview and testing redirect;
Reset itself sends no email. Retired pending/uncertain invitations are marked
`reset`, with their original delivery details remaining in Email history, so old
uncertainty does not permanently prevent a new reviewed training invitation.

Verification: `php scripts/tests/per_user_onboarding_reset.php <private-/tmp-dir>`
uses the all-table temporary fixture harness and simulated email transport. It
checks retained records/credentials/finance/baskets, other-user isolation, audit,
next-login activation and fresh invitation/resend. Run
`node scripts/tests/per_user_onboarding_reset.cjs <directory>` for the actual
per-row button/form, Cancel, exact phrase and CSRF/target binding. The shared
password/session-revocation fixture also passes. No real account was reset and
no real invitation was sent during implementation.

### People membership order and single email preference

The People membership selector uses `membership_types.display_order`, followed
by name and ID, matching Admin → Memberships and the public membership pages.
The onboarding selector still excludes draft and admin-only products.

People now shows only **Ride Reminders and Updates**, reading the existing
`ride_notice_opt_in` choice. Saving sets all three existing email consent flags
together on the Person and matching account and records the same values in the
onboarding preference evidence. An unchecked box clears all three; submitted
values for the two former controls are ignored. Failed validation retains the
submitted choice. Existing values are not bulk changed.

Checks cover the single rendered checkbox, all-on/all-off saves and evidence,
plus membership selector order and existing selection/basket behaviour, using
connection-local temporary database tables only.

Ride Reminders and Updates defaults to On when onboarding first shows the
People preference and when opening new-account registration. A saved onboarding
choice or an unchecked submitted form remains Off, including after validation
errors. The default does not change existing account preferences in the database.

The People Membership Options heading includes a green Membership Information
button, aligned right when there is room. It opens a native modal headed
Membership Types, with each available membership name and plain-text description
in the same saved order as the selector. Content comes from Admin → Memberships;
draft/admin-only products remain excluded. Top and bottom Close controls and
Escape dismiss it without submitting or validating the People form. Browser
fixture checks passed at desktop and mobile widths.

### Horse name changes and no-logbook choice

The horse list saves Do not purchase a logbook at this time directly via a
CSRF-protected POST, using the horse's saved name. There is no separate name
confirmation step. Change horse’s name opens a modal with Confirm and return to
horses, Cancel and Close; Escape also dismisses it. Saving returns to the horse
list and its Next button. Failed rename validation reopens the modal with the
entered name and field warning. Legacy keep-page links still work.

Renaming checks active ownership and the Horses onboarding stage, excludes the
system horse, retains valid logbook-choice evidence, and refreshes unpaid logbook
basket names without changing prices or hold timestamps. Name, evidence and
basket updates are transactional. Tests in migration_horses.php use temporary
tables; account_migration.cjs checks modal opening, prefill, Cancel and Escape.

Completion modal Close now links directly to My Account (`/account`), and
Escape navigates there too, avoiding a second Close on the underlying thank-you
page. Checkout remains available for baskets. Browser fixture checks covered
Close and Escape with and without basket items.

### Welcome text and shared heading

Admin → Onboarding → Welcome edits the shared account-setup heading and the
first-page HTML welcome message. Welcome is the first tab; the remaining tabs retain their order.
Before its first save, it uses the existing Account Migration welcome explanation
as the starting text; public welcome screens use that legacy value until an
explicit Welcome message is saved here. Saving a blank HTML message clears it.
The heading defaults to Complete your account setup, is plain text (maximum
180 characters), and applies throughout the onboarding screens.

### Tester-ready wrap-up — 20 November 2026

The Horses desktop actions share four equal-width columns and centred labels:
Buy a Logbook, Logbook not Required, Remove Horse and Change Horse’s name.
Narrow screens stack the controls. Welcome is the first admin wording tab.

Final regression pass covered combined account setup, conditional pages,
all-on/all-off email preferences, ordered membership offers, basket handling,
horse choices/renaming, invitation batches, closure, both workspace reset modes
and per-account next-login restarts. Browser checks covered the rendered flow,
wording/settings controls and both kinds of reset confirmation. All database
mutations used temporary tables; no real accounts, purchases or mail sends
were changed by this verification. Changes are for development testing; this
wrap-up does not deploy or enable migration in another environment.
