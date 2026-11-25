# Account migration email and setup pilot

Current status — 17 November 2026: the development campaign now supports
selected recipients and all accounts not yet successfully emailed. The initial
six-row import has five ordinary users plus the protected Developer. A read-only
check shows David's migration completed after its one invitation, and the other
five accounts not emailed. No new invitation or real account mutation was
performed while implementing this expansion. All automatic checks use fixtures.

The sender remains restricted to the opted-in development workspace and protected
Developer administrator. This is a wider recipient test, not staging/live rollout.
Sending requires the configured outgoing-email redirect to remain enabled; it was
verified as `clients@truska.com`. The redirect settings are never changed here.

## Run the pilot

1. As the protected Developer, open **Email → Campaigns → Account migration**.
2. Edit/save the email subject and Campaign text using TinyMCE. Save does not
   send. The system adds each recipient's personal setup button and expiry text.
3. Choose **Selected accounts** and tick the accounts, or **All who have not yet
   been emailed**. Successful previous sends are excluded from the latter;
   failures with no successful send can be retried. Completed/closed accounts,
   ambiguous person mappings and pending/uncertain deliveries are excluded.
   Active accounts of every role may be recipients when their Person match is valid. Up to 100 recipients per reviewed batch.
4. Preview the recipients and campaign. The server stores the exact reviewed
   recipient snapshots, draft revision and delivery redirect for 15 minutes.
   A resend of an already emailed account requires the explicit Allow resends
   checkbox. Resends invalidate older unused links without resetting progress.
5. Choose the explicit Send button. Each message retains its intended recipient
   and personal token, but the verified global redirect delivers all messages to
   the administrator. The existing redirect banner identifies the original
   recipient so the administrator can forward the correct email/link to each
   tester. A per-account report and invitation history show outcomes. Repeating
   an old Send submission cannot reuse the consumed preview.
6. An ordinary tester opens their forwarded personal link and chooses Continue.
   No prior login/password is required. GET/email scanners never authenticate or
   consume tokens. Continue consumes the single-use token and establishes that
   user's credential-bound session, gated to the saved setup journey. A browser
   logged into another account must sign out first; sessions are never silently
   replaced. All privileged or protected accounts still sign in normally first.
   **Password:** choose/confirm the new password (8–72 characters), without an old
   password question. Each field has an eye/eye-slash reveal control. The shared
   replacement mechanism revokes other sessions and old remembered/login/reset
   links while retaining the current browser. Role/protection/authenticator
   settings remain intact.
7. **User settings:** confirm first name, surname and existing login email.
   Preferences are asked only on Person settings; login email changes remain
   outside the migration flow.
8. **Person settings:** confirm names and email, phone, address, postcode,
   Junior/Senior classification, emergency name/phone, and DOB for juniors.
   DOB validation and the under-18-on-1-January classification match existing
   person handling. Optional email preferences are reviewed separately.
9. **Horses:** choose separately for each active managed horse. Buy collects the
   required details and adds the configured renewal-year logbook to the basket;
   Do not purchase confirms only the horse name; Remove requires confirmation,
   archives the horse and records an internal note. Each returns to the same
   list. Next requires a saved valid choice for every active horse. Accounts with
   none still see No horses to onboard and Next.
10. **Shares:** a To follow placeholder with Next and Back. It changes no share
    requests, links or permissions.
11. **Finish:** final saved-record and horse-review validation completes migration
    and shows the thank-you modal with Close and Membership – Join Now.

Warnings appear immediately above fields with red borders and accessible
associations. Failed submissions retain entered non-password values. Completed
steps save to the database together with their progress markers and preference
snapshots/timestamps. Back revisits earlier steps; revisiting Password again
asks only for the new password and its confirmation. Save progress and sign out keeps previously saved
steps; unsaved field edits are not retained. Every sign-in method resumes a
started unfinished flow. Direct account/admin/checkout/booking routes redirect
to setup before their handlers; Help and sign-out remain available.

The first Continue starts the mandatory gate. Sending an invitation alone
does not gate an account. A completed account cannot be re-enrolled by
resending. A developer-assisted new rehearsal should be explicitly reviewed
rather than silently resetting completion or password state.

## Invitation and storage behaviour

`account_migration.php` owns the pilot functions. `migrate.php` is the
controller; shared form/view files are in `views/migration_*`.
`migrations/2026-11-17-account-migration-pilot.sql` adds three InnoDB tables:

- `migration_campaigns`: the reviewed draft, subject and revision.
- `migration_enrolments`: target user/person IDs, durable step/timestamps,
  preference snapshots and deferred horse/share stages.
- `migration_invitations`: hashed random single-use tokens, account/email and
  credential binding, seven-day expiry, revocation and delivery status.

Opening moves the token from the URL to the session before rendering; responses
use no-store, no-referrer and noindex. The actual email necessarily contains the
personal link, and existing private email logs may retain its email body just as
for other account emails. Do not copy real invitation URLs into public reports.
Resending revokes older unused links and preserves progress. Changed account
email/password invalidates an outstanding link. Wrong-account, impersonation,
expired, revoked and replayed links fail closed. Password proof uses the shared
persistent login attempt limiter. Browser writes require the shared CSRF token.

The admin page applies the idempotent additive schema outside transactions.
Development's actual PHP worker (UID 5009) was checked for read access to the
new source/view/schema files; the public page returned HTTP 200 and a forged
POST without CSRF returned 403. Source files have mode 664. No upload folders
or external dependencies are introduced.

Import workspace reset clears enrolments/invitations, including the pilot's,
while retaining the migration campaign copy. The protected user remains covered
by the existing reset policy. Ordinary reset tooling has its own reviewed
retention policy and is not the single-login import rehearsal tool.

## Later stages and rollout

Horse review is now implemented after Person settings. Shares is a visible
To follow placeholder. No horse creation, logbook purchase, share approval,
new ownership or membership is inferred by these steps.

This is an existing-account development pilot, not the bulk invitation release.
Before widening it, implement the restricted invitation session and initial
password setup for ordinary imported accounts that have no known password,
review import provenance/enrolment mappings, recipient batches/retries, and
horse/share handling. Never broaden this by removing the pilot email check
alone. The protected pilot requires an authenticated account session and accepted
migration invitation before password setup. No old-password proof is required.
The wider invitation-only first sign-in flow remains future work.

For later deployment, follow `DEPLOYMENT.md`: back up the target, commit reusable
source on development, deploy with `git pull --ff-only`, then apply/check the
additive SQL. Do not copy development's private import-workspace opt-in or real
pilot records. This admin sender remains development-only; another environment
cannot enable it merely by spoofing the Host header.

## Verification

- `php scripts/tests/account_migration.php`: all installed tables are shadowed
  with connection-local temporary copies before mutations. Email transport is
  simulated. Covers pilot scope, stale copy, delivery failures, hashed tokens,
  scanner-safe inspection, expiry/resend/replay, wrong account/impersonation,
  invitation/session proof and password revocation, required fields/DOB/ownership, atomic progress,
  preferences, Back, final validation and reset references.
- For Chromium view checks, create a private temporary directory under `/tmp/`,
  pass it as the PHP test's first argument, then run
  `node scripts/tests/account_migration.cjs <directory>`. Fixtures contain only
  synthetic data. These check the actual rendered forms, field associations,
  preserved values, progress, Back/Finish and the thank-you dialog.
- Regression checks: `php scripts/test_session_revocation.php`,
  `php scripts/tests/import_workspace.php`, `php scripts/test_email_delivery.php`
  and `php scripts/test_auth_email_origins.php`.

No real email send or protected-account password edit occurs in these checks.

## Welcome choice and internal notes — 17 November 2026

The initial invitation screen now offers Continue and Close My Account after
sign-in to the invited account. Welcome text is also shown before sign-in.
Campaigns → Account migration → Welcome and closure wording stores optional
plain-text explanations; both start blank awaiting supplied wording. System
confirmation text always explains archival, sign-out and stopping migration.
The confirmation dialog has Back and an explicit confirmation checkbox/button.
Changing the wording invalidates an already-open confirmation revision.

Self-service closure is available only before the first Continue, for an
ordinary unprotected account with a valid personal invitation and exactly its
mapped active person. Additional active owned people require manual review.
Protected/administrative accounts, including David's pilot, cannot close here;
the modal explains this and offers Back. The ordinary closure branch is tested
with disposable connection-local fixtures; the pilot sender is still restricted
to David and no wider account has been invited by this implementation.

Confirmation archives the user and mapped person, clears both sets of email
preferences, sets migration state to `declined`, revokes invitations and account
login/recovery tokens, then signs the browser out. Later requests from other
sessions fail the existing archived-account check. It does not run subsequent
setup pages. No accounts, people, horses, shares or financial history are deleted.
A closed-account confirmation page replaces the setup journey. Replaying the
invitation cannot restore the account or restart migration.

The archive transaction appends one internal `migration_declined` note for the
user and one for the person. Both share an event key. Each note retains:
ID, UK-local datetime, record type/ID, email at the time, action, note, author
kind and author user ID. Metadata records the exact confirmation text/version,
page-copy revision, invitation ID and linked target IDs. Failure to write either
note rolls back the entire operation. Notes survive ordinary archival and email
changes; they are stored separately from editable account/person fields.

New additive SQL: `migrations/2026-11-17-internal-record-notes.sql`. It creates
`record_notes` and `migration_page_copy`; `ensureMigrationTables()` prepares
both. The development database contains empty notes and page-copy configuration;
no real closure or closure note was created during implementation.

Admin → Users → Internal record notes opens `admin/record_notes.php`; user rows
and person editors link to filtered notes. Only Admin, Superadmin and Developer
can read the page. Filter by email, type, record ID, action or date range. It
includes archived records and stored confirmation evidence; it has no public
note API or edit/delete form. Person/user-facing forms do not expose notes.
Notes are append-only through the application; no database immutability trigger
is claimed. A retention/purge policy remains a separate decision; archiving is
not a claim of GDPR erasure or complete legal compliance.

Import workspace rehearsal reset deliberately clears `record_notes`, including
notes about the protected pilot, together with enrolments/invitations; a complete
private backup precedes that reset. Campaign and page wording remain. Ordinary
record archival does not clear notes. Deploy reusable source from development
with the usual backup and `git pull --ff-only` workflow; apply both additive SQL
files and verify actual PHP read access. Never broaden the protected pilot by
removing its email check alone.

Extended checks in the existing PHP/Chromium migration suite cover confirmation,
stale copy, invalid invitation, impersonation, protected account refusal,
extra-person review, rollback on the second note failure, private correlated
notes, filters/email snapshots, token revocation, archived-session invalidation,
Back, the protected modal and the closed-account screen. The existing import
workspace fixture test also passed after adding notes to its reset allowlist.


Development link/style correction: new migration invitations and the preview use
`https://dev-ildra.witecanvas.com:28086` from the central approved-origin map.
Previously sent email URLs are unchanged. Setup loads only the shared
`views/back_navigation_styles.php`, keeping normal Bootstrap button weight;
it no longer loads public-site button CSS. Staging/live origins are unchanged.


Password-step update: removed the old-password input and server-side check at
the user's request. The password step still requires the same authenticated,
credential-bound owner session and durable accepted migration state; it cannot
be submitted before Continue, for another account, or while impersonating.
Length/confirmation checks, atomic progress, existing shared password replacement
and other-session/token revocation remain. Both new-password fields start masked
and have independent accessible Show/Hide controls. PHP/Chromium migration tests
and the session-revocation regression passed with temporary records; no real
password was changed during implementation.

Onboarding preference update: ask for email preferences only on Person settings.
The User step saves identity details without altering preferences or recording
unasked choices. Saving the mapped Person applies the explicit choices to that
person and the matching login user in the same transaction, with matching
preference snapshots. Other people are not modified. Outside onboarding,
existing account/person preference screens retain their established behaviour.
The Person form labels are Full Postal Address, bold Postcode, and Weekly Ride
Reminder Email. A clarification explains that the ride preference also controls
ride-entry opening/closing notices. Isolated PHP and browser checks cover the
single preference form, matching saved choices and updated labels.


## Selected-recipient expansion details

The original single-account pilot restrictions described in older sections above
are historical. Current recipient sending uses `migrationSendRecipient()`;
`migrationSendPilot()` remains a compatibility wrapper for the original fixture.
Active ordinary users with one matching owned person, and the existing protected
Developer pilot, can appear in the selector. Existing terminal states are never
reset. Names, emails, credentials, person mappings, progress and delivery counts
are rechecked against the approved snapshot before each send. A changed account
or redirect requires a fresh preview. A failed per-account send does not prevent
remaining reviewed recipients from being attempted, and the report records each.

`send_logged_email()` accepts an internal `required_test_redirect` guard. Migration
passes the reviewed address; the guard checks the very settings snapshot used by
the transport and fails without sending if the redirect was disabled/changed.
The usual redirect removes original CC/BCC and retains logical recipient metadata.
A pending/uncertain delivery is held for manual review rather than automatically
retried after an interruption. A successful SMTP handoff is recorded as sent;
it is not proof of inbox delivery. No new delivery queue/schema was needed.

Ordinary users may also decline directly with the valid invitation and explicit
confirmation before starting, without knowing an old password. Protected/admin
closure and impersonation remain blocked. The first-page closure choice is hidden
for already-started journeys; resends let those users resume their existing step.
Closing archives and stops the journey exactly as documented above.

Before rollout beyond development, follow DEPLOYMENT.md and deploy committed
source from development with `git pull --ff-only`; do not copy this workspace's
private opt-in or test data. Removing the outgoing redirect is deliberately not
supported by this test sender and needs a separately reviewed live-send change.

Additional checks: `php scripts/tests/migration_batches.php` reuses the verified
all-table temporary fixtures, with simulated transport, to cover selected/unsent
recipients, completed/archived/admin exclusions, mapping conflicts, stale previews,
resend confirmation, failed retries, uncertain-delivery holds, redirect changes,
ordinary invitation entry/password/closure, replay and wrong-account refusal.
The email-delivery fixture verifies the pinned redirect rejects changed/disabled
settings without attempting SMTP, while valid routing removes original CC/BCC.
Chromium setup checks now include anonymous ordinary and wrong-account welcome
screens. All checks passed without sending a real email.


## Horse onboarding and Shares placeholder — 17 November 2026

The current journey is Password → User → Person → Horses → Shares → Finish.
This supersedes earlier notes describing both later stages as deferred. Ordinary
and protected test accounts use the same stages after accepted invitation entry.
No-horse accounts deliberately see a page and Next for now; there is no bypass.

Only active `horses.owner_user_id` records are editable. System horse ID 1,
archived horses and shared select-only horses are excluded. All owned active
horses must be submitted together; missing, injected or other-account IDs stop
saving. Fields/validation match the existing required horse registration details,
with warnings immediately above each affected control and red mandatory markers.
Height choices show cm/hh; an existing custom positive cm value is retained.
Unknown qualification remains blank until chosen. Legal owner name is explicitly
entered, not inferred from the managing account.

All field updates, per-horse confirmation evidence and stage advancement share
one transaction. The new additive
`migrations/2026-11-17-migration-horse-reviews.sql` creates
`migration_horse_reviews(user_id,horse_id,confirmed_at,record_hash)`. Other legacy
fields (DOB, passport issuer and vaccination date) are preserved when not shown.
No horse, logbook purchase or membership is created by onboarding.

Horses Next records `horses_status=reviewed` (or `none` after the empty page),
then displays Shares. Shares Next records only `shares_status=placeholder_seen`
and advances to Finish. This is not sharing consent or permission approval.
Back from Finish returns to Shares, Shares to Horses, and Horses to Person.
Later sign-in resumes the current saved stage. The existing footer controls and
password-step exceptions remain.

Finish requires valid user/person details, current matching horse-review hashes
and the Shares placeholder visit. Changed/new/archived/transferred horses send
an unfinished flow back to Horses for review. Older unfinished Finish states
also receive the new stages. Already completed accounts are not automatically
reopened. The development schema was prepared with existing accounts, horses,
progress, logbooks and shares verified unchanged. PHP worker schema readability
and HTTP 200 for the onboarding page were verified; SQL uses mode 664.

Import workspace reset now clears horse-review evidence with other operational
migration state. No FK or reusable source correction was made on staging/live.
For rollout, follow DEPLOYMENT.md and apply the additive SQL outside transactions.

Tests: `php scripts/tests/migration_horses.php` uses verified temporary tables
and covers multiple horses, all required fields, ownership and approved shared
select-only isolation, missing/forged IDs, rollback when the second review write
fails, hidden-field preservation, changed/new/archived horse refresh, Back,
placeholder semantics, completion and reset policy. Existing migration batch and
reset fixtures passed. Chromium covers horse fields, accessible errors, empty
Horse Next, Shares Next and the full six-stage progress display. No real horse
details, sharing permissions or purchases were changed and no emails were sent.


## Per-horse logbook choices within onboarding — 17 November 2026

This replaces the initial all-horse details form described above. The Horses
list uses the supplied explanation about last year's logbooks and offers three
buttons for each active managed horse. Every child page is a `migrate.php`
subview, retains the Horses progress marker, and has Back to horses plus the
existing save/sign-out footer. Normal logbook/basket/checkout routes stay gated
until onboarding completes; no broad route exception was added.

- **Buy a logbook**: show the existing published product, its configured renewal
  year and price. Collect/validate the standard full horse details. Add one
  normal `horse_logbook` basket item and return to the list. Current development
  configuration is the 2027 Horse Logbook at £7.50; this is read from settings,
  not hardcoded. Posted prices/types/years are not trusted. A stale quote,
  unavailable product or purchasing maintenance blocks a new selection.
- **Do not purchase a logbook at this time**: confirm/edit only the horse name.
  All other saved fields are retained, including unknown fields remaining blank.
  Remove that horse's unpaid logbook lines and return to the list.
- **Remove the horse from the system**: a confirmation page explains archival
  and requires an explicit checkbox. Archive the horse, retain history, remove
  its unpaid logbook basket items, and append a private `migration_horse_archived`
  note with actor/email/name and prior choice metadata. Return to the list;
  archived horses are no longer among its active choices. This never deletes
  paid purchases, bookings or share history, and does not issue refunds.

Full details are mandatory for Buy only. Keeping a name-only horse is an explicit
valid onboarding choice. System horse ID 1, archived records, other accounts'
horses and select-only shared horses cannot be acted on. Shares remains a To
follow placeholder and no sharing permissions are changed.

`migration_horse_choices.php` implements the operations. Additive SQL
`migrations/2026-11-17-migration-horse-choices.sql` creates
`migration_horse_choices`, keyed by user/horse, storing the choice, confirmation
time, relevant horse hash, offer type/year/price and basket item identifier.
Existing full-detail evidence remains in `migration_horse_reviews` for Buy.
Keep uses a name/manager hash and does not invent full-detail confirmation.
The new table was initialised with existing users/horses/purchases/progress and
baskets verified unchanged. Runtime source/view/SQL files use mode 664; actual
PHP read checks passed and the migration endpoint returned HTTP 200.

Each choice commits the horse edit, choice/review, basket write and optional
archive note together. Storage failures roll back all parts before session
basket state is updated. No schema helper executes inside these transactions.
Pending basket items use the existing application's product shape and checkout;
adding an item never creates a paid logbook entitlement or charges a payment.
Existing logbook purchases for the year are preserved without adding duplicates.
Repeated Buy does not add a second line. Switching to Keep/Archive removes unpaid
logbooks for that horse while preserving unrelated basket items and paid history.
Removing items does not refresh unrelated booking hold timestamps.

While migration is unfinished, saved logbook intent is reconciled into the
current session's basket after sign-out/expiry. Only confirmed choices at the
same offer/year/price are restored; changed choices return for review. Moving
those unpaid lines from an older session preserves its unrelated entries. The
code rejects a current basket belonging to another account. No completed
account is automatically reopened or has its basket rebuilt. Existing checkout
revalidates availability and performs payment/purchase creation as usual.

Horse-list Next checks every active horse has a current valid Buy or Keep
choice, then advances to Shares. Changed/new horses or a changed pending offer
return an unfinished later stage to Horses. A reviewed archive naturally removes
that horse from the active set. No-horse Next remains visible. Finish restores
pending selections before completion; the thank-you page/modal offers Checkout your basket
when nonempty, alongside Close. Membership selection is now on Person settings.

Import workspace reset clears `migration_horse_choices` with the other reviewed
operational migration tables. The existing backup procedure covers it. Deploy
reusable changes from development only via the normal backup/commit and
`git pull --ff-only` workflow; apply the additive SQL outside transactions.

The updated `scripts/tests/migration_horses.php` uses verified temporary tables.
It covers each branch, required-field warnings, name-only Keep, canonical pricing,
stale/unavailable offers, duplicate and existing-purchase protection, basket-write
rollback, archive-note failure rollback, cross-session restoration, cross-account
basket rejection, maintenance, internal notes, ownership and paid/share history.
Existing invitation, reset, logbook product and purchasing-pause fixtures passed.
Chromium checks the list, each child page/return link, confirmation errors and
View basket on completion. Tests send no real emails, take no payments and do
not edit/archive real horses.


### Membership selection during onboarding — 17 November 2026

Person settings now ends with an optional Membership box. The dropdown shows
published, publicly purchasable membership names and prices; administrator-only
and honorary memberships are excluded. The configured purchase year is shown.
Add membership to basket validates and saves the Person form, checks a server
quote for the current type/year/price, and adds the mapped person's membership
within the same transaction. It stays on Person settings with confirmation;
Next continues to Horses and does not require buying a membership.

Repeated Add replaces the unpaid membership for that person/year rather than
creating duplicates, including lines in older sessions. Existing purchases
block another purchase. Purchasing maintenance uses the existing role rules.
Unrelated basket items remain intact. No payment, membership number or paid
membership entitlement is created by adding to the basket. On an unfinished
migration, unpaid onboarding membership lines resume from a previous session.
No additional schema is required. Ordinary checkout completes the purchase.

Completion removes Membership – Join Now and View basket, offering Close and,
only when items exist, Checkout your basket linking to /checkout, both in the
thank-you page and completion modal. The finish explanation covers memberships
and logbooks together.

Verification: scripts/tests/migration_memberships.php uses temporary tables to
check type validation, canonical prices, stale quotes, duplicate prevention,
return to Person, cross-session resumption and absence of paid entitlements.
The shared migration, horse and invitation tests pass. Chromium fixtures check
the dropdown labels/prices and conditional checkout links. No real emails,
accounts or purchases were changed by these checks.

### Horse name correction and name separation — 17 November 2026

At the account holder's request, horse 25's name was restored to Breeze, retaining
Fiona Cunningham as owner. Its unpaid logbook basket line was corrected, matching
review hashes updated, and an internal correction note recorded. The previous
rows are backed up privately in import-workspace-backups under the
breeze-name-correction prefix. Other horse details and paid history were retained.

The CSV importer maps Horse name to horses.name and leaves owner_name blank;
email identifies the managing account only. Added explicit regression checks for
that distinction and for separate horse/owner values through onboarding into the
basket. Both isolated suites passed. No mapping defect was found; the cause of
the previously saved name is unconfirmed. Horse detail inputs now request that
browsers disable autofill to reduce unintended personal-name substitution.

## Journey refinement — 20 November 2026

Password and User confirmation are now one public account page, with an atomic
combined save and immediate Next to Person. Admin → Onboarding → Settings controls
Horses, Shares and the single shared Show Blank Pages option. Empty enabled
stages skip by default; Show Blank Pages=Yes exposes them for testing. See
[Onboarding wording and flow](ONBOARDING_WORDING.md#refined-journey-and-optional-pages--20-november-2026)
for defaults, progress/back behaviour and the temporary-fixture checks. This
supersedes the earlier fixed Password → User → Person → Horses → Shares journey.
The legacy separate save helpers remain for internal compatibility; public
password/user submissions use the combined save and cannot bypass identity
confirmation.

## Production rollout follow-up — 20 November 2026

Production readiness is now implemented separately from the development pilot:
see [ONBOARDING_LIVE_ROLLOUT.md](ONBOARDING_LIVE_ROLLOUT.md). A private
destination opt-in binds approved host/origin, installed root and database.
Developer/Superadmin can prepare/send reviewed invitations when enabled; reset
functions remain development-only. CLI imports use a separate temporary opt-in
and do not enroll/email accounts. Populate via ildra.witecanvas.com, then verify
DNS/TLS and use enduranceridingireland.com before real invitations. Existing
development pilot redirect restrictions are retained.


Recipient eligibility update — 25 November 2026: `migrationRecipient()` and
`migrationRecipients()` no longer filter by role. Active-account checks and the
matching active Person requirement remain. Administrative/protected recipients
must still sign in normally with a credential-bound session before accepting;
invitation acceptance preserves their roles, permissions and event links. No
schema migration is needed and this change sends no invitations by itself.
Verification: `php scripts/tests/migration_recipient_roles.php` covers all role
names, archived/mismatched/missing-Person rejection, privileged sign-in and
unchanged organiser links using temporary tables. The existing migration suite
now sets its own synthetic redirect rather than relying on current site settings.
