# Development import workspace

Prepared 15 November 2026 for repeated Event-Pal import rehearsals. This is
preparation tooling: no real reset, CSV import or invitation send has run.

## Protected account

The development account `david@truska.com` (ID 2) is now Developer (level 7)
and has `users.is_protected=1`. Its original account/role records were backed
up privately before this change. Password and authenticator settings were
not changed. Bootstrap refreshes the role on the next request.

Protected means sign-in remains available. Admin edits, role changes,
administrative password resets, deletion and archiving are blocked. The
owner can still change personal details, password and authenticator through
normal self-service verification; the protected email cannot change.
Protection is enforced by application handlers, not a database trigger.
There is deliberately no protection toggle in the user editor.

`user_protection.php` creates the additive protection column via bootstrap;
new/existing ordinary accounts default to unprotected. Marking a particular
account protected and assigning Developer are environment-specific operations,
not automatic source migrations or changes to another environment.

## Tech → Import workspace

Route: `admin/import_workspace.php`. Only the protected Developer account can
use it. The private `../private/import-workspace.php` opt-in pins the database,
physical checkout path, development hostname and protected account ID/email.
Copying source or spoofing a Host header is insufficient to enable it elsewhere.
The development hostname accepts an optional valid port, including the workspace
proxy on `:28085`; the database and physical checkout pins still apply.
A second protected account blocks resets until the retention policy is reviewed.

1. Stop testing, scheduled email sends and payment/webhook processing; close
   other browser baskets. This tool does not stop external workers itself.
2. Preview the deletion counts. The preview binds the account, reset plan and
   row-content hashes, expires after 15 minutes, and is checked again under locks.
3. Check the acknowledgement and type `RESET DEV IMPORT DATA` to run it.
4. The tool locks the database tables, writes a complete schema-and-data JSONL
   backup outside the website, flushes it to disk, then deletes in one transaction.
   No deletion occurs if backup creation or validation fails. Failures roll back.

Backups: `../private/import-workspace-backups/`, files mode 600. The directory
is pre-created with a named write ACL for PHP user `web7`. An HTTP probe verified
actual PHP UID 5009 could read the private config/new source and create/remove a
backup test file. ACLs are local deployment state and do not travel through Git.
Backups include authentication and personal data; keep them private. Recovery
requires an administrator to restore the schema/row records while the site is
quiet; there is no public restore/upload endpoint.

The reset removes all other logins, every person, operational horses, booking
items/bookings/results, membership and logbook purchases, financial transactions,
credits, loyalty cards, Stripe customer links, payment requests, share records,
issued coupons/redemptions, pending opening balances, baskets, email logs,
campaign recipients/test history and deleted users’ authentication tokens.
The protected account and its authentication tokens remain. No Stripe-side
records are deleted or refunded, and no emails are sent by this tool.

Preserved: website content/images, events and their setup/pricing/notes, all
reference/type definitions, menu, awards, email templates/campaign definitions,
development tasks, private source files, historical `_backup_` database tables
and `x-source-` tables. The global horse ID 1 (“Not Registered”) is configuration
and remains. Optional references to deleted accounts are cleared. Mandatory task
authorship transfers to the protected account; stored author names remain.
Unreviewed new identity references or foreign keys block the reset.

Campaigns return to Draft with cleared recipient/counter/approval state, and
`campaign_live_sending_enabled` becomes 0. Other email settings, including SES
routing and the testing redirect, remain unchanged. Membership numbering returns
to 1000. Primary-key sequences are not reset, preventing old links from pointing
to newly imported accounts/records with recycled IDs.

The earlier **Reset test data** tool remains available with its existing policy
of retaining all logins and selected people. Use **Import workspace** for this
new single-protected-login rehearsal policy.

## CSV and next stage

Upload originals via SFTP/file manager to a dated folder under
`../private/source-data/`, following [SOURCE_DATA.md](SOURCE_DATA.md). Nothing
watches or imports that directory. Do not upload source CSVs to public media.
PHP access to a new source delivery will be granted only as needed for the
reviewed importer; this tool does not broaden source-folder access.

Expected stage-one headers: `email,first_name,last_name`. Review the sample,
matching, duplicate handling and proposed accounts before implementing/running
the importer. The persistent guided onboarding and invitation flow remain
separate work in the [account migration plan](plans/ACCOUNT_MIGRATION_AND_FIRST_LOGIN_PLAN.md).
Uploading does not authorize importing, and importing does not authorize email.

## Checks

- `php scripts/tests/import_workspace.php`: shadows every installed table with
  a verified connection-local temporary table before mutations. Covers deletion,
  preserved login/calendar/system horse, reference cleanup, repeat runs, rollback,
  protection handlers, private backup, stale preview and unknown-reference denial.
  Its temporary-table backup is removed after verification. No real records are
  deleted and no emails are sent.
- `php scripts/test_session_revocation.php`, `php scripts/test_auth_app_security.php`
  and `php scripts/test_auth_throttle.php`: existing authentication regression tests.
- `php scripts/tests/admin_menu_dividers.php`, `php scripts/test_email_delivery.php`
  and `php scripts/test_auth_email_origins.php`: menu/email regression checks.

Keep reusable changes on development and follow [DEPLOYMENT.md](DEPLOYMENT.md)
if later deploying source. This development reset opt-in must not be copied to
staging/live, and staging must not receive direct reusable source corrections.

## Small users/people import trial — 17 November 2026

The authorised six-row development trial completed: five ordinary User logins
and six owned people were created. The existing protected Developer user was
compared byte-for-byte as a fetched database row and remained unchanged. No
emails, invitations, membership numbers or memberships were created.

CLI tooling: `scripts/import_user_people.php`. Pass the private CSV path for a
read-only preview; append `--apply` for the explicitly authorised import. It is
restricted to the opted-in development database using the Import workspace
pins. It accepts the agreed headers and the supplied `Email Address,First
Name,Surname` variant, including a UTF-8 BOM. It rejects invalid fields and
normalised duplicate emails before changing data. Original files stay private
and unchanged; future deliveries belong under `private/source-data/`.

Existing users are preserved in full; names must match and archived accounts
require separate review. Existing owned people are reused only for a single
exact email/name/owner match. Ambiguous people, shared-email conflicts and
changed source details stop the entire batch for review. New users receive the
ordinary User role and an unknown random password hash; all email preference
flags are initially off on both new records. Missing person details and
membership numbers remain unset.

Apply locks the database rows, rechecks the preview, writes and flushes a full
private schema/data backup, then imports all pairs in one transaction. Backups
and source-row/target-ID receipts use mode 600 in
`private/import-workspace-backups/`. A successful command reports commit;
receipts alone are not proof of commit after a process interruption. The
17 November trial backup begins `accounts-20261117-102722-079a87076a9a421d`.
Reruns preserve existing exact pairs and never overwrite user corrections;
changed details require reconciliation against the private receipt. A durable
migration mapping, onboarding/password-setup flags and invitation workflow
remain future work before wider migration. This script does not implement
or enforce the proposed guided setup journey.

Verification: `php scripts/tests/import_user_people.php` uses connection-local
temporary users/people tables to check matching, conflicts and preservation,
plus a private temporary CSV for BOM/header/duplicate validation. The live trial
also verifies existing user rows before commit; the subsequent preview confirms
all six user/person pairs are preserved on rerun.

## Migration email pilot

See [Account migration pilot](ACCOUNT_MIGRATION_PILOT.md) for the dedicated
single-account campaign and mandatory password/user/person setup. Import
workspace reset also clears `migration_enrolments` and `migration_invitations`,
including any protected pilot progress, while preserving campaign copy in
`migration_campaigns`. No real invitation has been sent during implementation.

The later closure choice adds `record_notes` to this reset's deletion allowlist.
Ordinary archives preserve those notes, but the development rehearsal reset
clears them (including notes about the protected user) after its full backup.
`migration_page_copy` is retained alongside migration campaign copy.

## Horse-name/account import trial — 17 November 2026

The approved private `horse-logbooks-testers.csv` delivery contains only
`Horse name,Email address`. All eight rows matched active managing accounts;
eight horses were created under five accounts. Other horse details remain NULL.
No logbook purchases, memberships, shares or email sends were created. The
original CSV remains unchanged outside the web root; future deliveries should
use a dated directory under `private/source-data/`.

CLI: `php scripts/import_horses.php <private CSV>` previews; `--apply` performs
the authorised import in the pinned development workspace. It validates UTF-8,
BOM/headers, names, addresses and repeated source names, and requires exactly one
active matching account for each new row. Existing horse names are review
signals: no automatic merge, ownership transfer or second record is inferred.
The CSV supplies the managing account, not the horse's legal owner name. The
reserved system horse ID 1 is preserved.

Apply takes the shared import/reset advisory lock, locks/rechecks the database,
writes/fsyncs a full private schema/data JSONL backup, then inserts all horses
in one transaction. Every other table is hashed before/after and required to be
unchanged; every pre-existing horse is also compared exactly. The successful
trial backup is `horses-20261117-124827-da6176bdc438cdd2.jsonl` in the private
import-workspace-backups directory, mode 600.

The CSV has no stable legacy horse ID. A mode-600 source-hash receipt in that
same private directory maps its rows to permanent user/horse IDs and the horse's
creation timestamp. Reruns of the unchanged delivery preserve mapped records,
even if the owner has corrected/archived a horse or changed their email. A
subsequent preview confirmed all eight target IDs were reused. Mapped ownership
changes, missing targets, changed deliveries and name conflicts require review.
A receipt is written before commit; it is not standalone proof of commit after
an interruption. Target existence/ownership is always validated. After a full
rehearsal reset, retain the old receipt with its backup and reconcile it before
starting a new import; do not blindly reuse or delete mappings. Future broader
exports should include stable source horse IDs as planned.

`php scripts/tests/import_horses.php` uses verified temporary users/horses tables
and private synthetic CSV/receipt files. Covers parsing, conflicts, unknown or
archived owners, rollback, protected-user/system-horse preservation, receipt
permissions, corrected/archived reruns and missing/changed-target refusal.
No real data is mutated by this test. Public HTTP access to operational scripts
remains denied under the existing scripts rule; files use mode 664.

The horse import created records only, without changing onboarding progress.
Horse review was subsequently added to the migration journey; see
ACCOUNT_MIGRATION_PILOT.md for Horses and the Shares placeholder.
David's separate requested restart was backed up, old invitations revoked and
an internal `migration_rehearsal_reset` note appended. His user/person records
were verified unchanged; his state is unstarted Password, ready for a fresh
explicit resend from Account migration (Allow resends is required).


The later Horse onboarding stage adds `migration_horse_reviews` to this reset's
operational deletion allowlist. Reset clears per-horse confirmation evidence,
while ordinary onboarding saves and archive operations preserve the horse
records and any unrelated logbook/share history.


Per-horse Buy/Keep/Archive onboarding adds `migration_horse_choices` to the
operational reset deletion allowlist. Paid logbook history remains governed by
the existing reset policy. Outside a rehearsal reset, onboarding only adds unpaid
basket selections; horse removal archives and writes a private record note.
See ACCOUNT_MIGRATION_PILOT.md for the current workflow and rollback tests.

## Onboarding settings reset modes

Onboarding → Settings now exposes two reset choices to the same protected
Developer in this opted-in development workspace: keep users and rebuild minimal
Person records, or clear unprotected users as well for a new import. Both reuse
the locked full-private-backup runner, deletion allowlist and content-retention
policy. The original Import workspace tool retains its existing behaviour.
See [Onboarding reset controls](ONBOARDING_WORDING.md#onboarding-test-data-reset-controls)
for mode details, confirmation requirements and isolated tests. Implementation
performed no real reset, import, invitation send or external payment operation.

Production population no longer requires enabling the development reset
workspace. The reviewed CLI user/horse scripts now also support the independent
`allow_imports` permission in the destination's private `account-migration.php`,
bound to its installed root/database and an active Developer/Superadmin operator.
See ONBOARDING_LIVE_ROLLOUT.md for preview/apply sequencing. Destructive workspace
resets and per-user testing restarts remain restricted to development.
