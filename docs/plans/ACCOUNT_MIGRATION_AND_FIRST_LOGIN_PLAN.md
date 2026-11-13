# Account migration and first login plan

Status: proposal for staged review. Importers, onboarding and migration
invitations are not implemented by this document. No data has been imported
and no email send is authorised.

The agreed first delivery is a single CSV with email, first name and surname.
Each resolved row creates one ordinary login user and one person owned by
that user. All other person details are entered during guided first setup.
Shares and horses remain later stages.

Email is the source matching key for this initial delivery, normalised to
lowercase after trimming whitespace. Internal database IDs remain permanent
identifiers after creation. Import and invitation sending are separate actions.

## Existing support

Users are logins; people are rider records. Each person and horse has one
managing account through owner_user_id. Other users receive select-only
access through user_person_links or user_horse_links. A horse's owner_name
can differ from its managing account.

Magic links exist in auth.php, but default to 15 minutes and are capped at
one hour. They cannot be used unchanged for bulk migration invitations.
Account editors and person completion prompts exist; the persistent ordered
journey below is new work.

Relevant implementation: auth.php, auth_sessions.php, cms.php, account.php
and views/horse_details_fields.php.

## Agreed initial CSV

Use UTF-8 CSV with these three headers:

    email,first_name,last_name

One row represents one account holder and their own person record. The
surname column can be named surname if agreed in the field mapping.
No source ID, password, membership number, address or other person fields
are required in this delivery.

The importer must trim and lowercase every email even if the export already
does so, validate addresses, and detect duplicates after normalisation.
Do not remove dots or plus suffixes. Duplicate rows, conflicting names and
existing target accounts appear in the preview; do not silently select one.

This format intentionally cannot represent several different people sharing
one email. Hold those cases for resolution or a separate later people file
with stable person IDs. Do not assume an existing matching email identifies
a particular existing person record. Never create a second person blindly
when a matching account already has people.

Include source system event-pal, export date and a delivery identifier in
the delivery notes. Deliver under ../private/source-data/<delivery-name>/,
following docs/SOURCE_DATA.md. Keep originals unchanged, cleaned versions
separate, and personal data outside Git and public folders. Uploading does
not authorise importing.

## Stage 1 Users and people

One import operation performs two internal steps per resolved row:

1. Create the ordinary login user with lowercased email and supplied names.
2. Create their person with the same email and names, linked by owner_user_id.

Apply the pair atomically after preview approval. Finish schema setup outside
transactions. Existing accounts and uncertain person matches go to a reviewed
exception path; do not overwrite their password or duplicate their person.

### Password handling

Do not import passwords. Each newly created user receives a hash of a unique,
cryptographically random secret which is never displayed, emailed, logged
or retained as plaintext. This is an inaccessible initial credential, not a
temporary password issued to the user. People records have no passwords.

Keep an explicit password_setup_required flag. Setting a password through
recovery must not bypass the remaining onboarding stages. Existing accounts
retain their credentials and need separately reviewed completion requirements.

### Data origin

Proposed record metadata on imported users and people:

| Field | Initial value or purpose |
| --- | --- |
| origin_source | event-pal |
| origin_batch_id | Identifier of the reviewed import batch |
| imported_at | Import timestamp |

Keep the normalised original email in a private import mapping with the target
user/person IDs, batch and source row reference. Later email changes must not
erase provenance or create new records on a rerun. The immutable target IDs
anchor established mappings; changed source emails need reconciliation.

Store provenance separately from editable names and contact information.
The origin remains event-pal after the person corrects their details.
A later record created manually should have its own origin, not inherit the
account's import source automatically.

### Initial completion requirements

Only email and names are imported. The first setup requests phone, address,
postcode, Junior/Senior classification, emergency contact name and phone,
and DOB for juniors, alongside review of the imported names and email.
Reuse current age validation. Ask for other applicable details such as
qualification using an explicit unknown/review state where needed; do not
invent a qualification or paid membership.

Email preferences and any required declarations are collected during setup,
with acceptance timestamps and wording versions. Do not infer email opt-ins
from the imported email. No membership numbers are imported or allocated during onboarding. New
membership numbers are allocated when people join for 2027. Historical
membership or entitlement imports remain a separate scope.

## Stage 2 People sharing

Export one row per relationship:

| Column | Purpose |
| --- | --- |
| source_share_id | Stable relationship identifier |
| source_person_id | Person being shared |
| owner_source_user_id | Managing account for validation |
| recipient_source_user_id | Account receiving access |
| status | Approved, pending or revoked in the source |
| permission | Source permission, explicitly mapped to select_only |
| evidence_ref | Source authorisation/relationship reference |

Include created_at, accepted_at and revoked_at where available. If recipient
IDs are unavailable, provide recipient_email for reviewed resolution.

Import approved access only where the source explicitly records it and the
mapping is approved. Family names or booking history alone produce review
candidates or pending invitations. Never mark a pending share accepted or
reactivate a revoked one. Unresolved recipients go to an exception report.

Keep one person record and one managing account. Sharing adds a link; it
does not duplicate the person or transfer ownership. The current system's
select-only permission does not give shared users editing rights.

## Stage 3 Horses

Minimum export: source_horse_id, owner_source_user_id and name.

Also export wherever available: owner_name, dob, year_of_birth, breed,
colour, qualification_code or qualification_name, passport_issuer,
passport_number, sex, height_cm, flu_vac_date and is_archived.

The managing account and actual owner must be mapped separately. Supply
height units; convert to centimetres rather than treating hands as decimals.
Sex currently accepts Mare, Gelding or Stallion. Map qualification names/codes
to target lookups rather than copying source numeric IDs.

The current detailed horse validator requires name, owner_name, breed,
colour, sex, height_cm, qualification, year_of_birth and passport_number.
Import may retain incomplete records for later completion. Do not invent
passport numbers or treat an unknown qualification as None.

Use source IDs for identity. Passport issuer/number are duplicate-review
signals; horse name alone is insufficient. Multiple riders must not cause
duplicate horse records.

Horse shares use the people-sharing columns with source_horse_id replacing
source_person_id. Import horses before their shares. Logbooks, purchases,
expiry, vaccination history beyond the existing single date and competition
history require separate exports and scope.

## Proposed first login journey and initiation

1. After an approved import, initialise each new account with
   onboarding_status=not_started, current_step=password and
   password_setup_required=true. Create the per-person review requirement.
   Importing does not send email or mark addresses verified.
2. Preview recipients and email wording; send an approved pilot before approved
   batches. Send one invitation per account and track sent, failed, bounced,
   activated and completed independently.
3. Create dedicated hashed, single-use, revocable invitation tokens when
   sending. Proposed validity is seven days, to be agreed. Resending invalidates
   the previous invitation. Existing short-lived magic links stay unchanged.
4. Opening the link shows Continue. Consume the token and create a restricted
   onboarding session only on a protected confirmation POST, so email scanners
   cannot activate accounts by opening links. Do not silently replace a session
   belonging to a different account.
5. Create and confirm a password under the current password policy. Newly
   imported accounts with valid invitation proof do not need an old password.
   Reuse credential/session revocation and bind the resulting session to the
   new credential. Existing accounts authenticate normally without forced
   password replacement.
6. Next: confirm user details: prefilled name/login email, preferences and required
   declarations. Store new acceptance dates and wording versions. A changed
   login email must be verified at the new address.
7. Next: complete People setup: owners correct and explicitly confirm each owned record.
   Show shared people separately for access confirmation or decline. Shared
   select-only records cannot be edited; provide a route to report a wrong
   owner, duplicate or incorrect record.
8. Next: review horses using the same owner/shared distinction. Complete required
   details, confirm relevance or report an issue. Accounts without horses can
   explicitly confirm that and continue.
9. Show a completion summary and open the dashboard. Unresolved record details
   continue to block only the membership/logbook/entry actions requiring them.

Each screen has Back and Next controls; Next validates and saves the current
step. Final completion uses Finish. Show progress as Password → User →
Person → Horses → Finish. Prefill imported email and names; leave the other
fields for the user to supply. Do not allow Next to skip missing required
details. Support Save and finish later, resuming at the first incomplete step.

Save progress in the database after every step, including individual record
confirmations. Every login method resumes the first incomplete stage. Enforce
the onboarding gate server-side on protected actions; allow logout, recovery
and help. Do not infer completion from last_login_at or populated import fields.

If horses are deliberately imported later, mark that stage deferred instead
of making users create duplicate horses. Reopen review when the batch arrives.
Stage 1 can therefore be delivered independently without inviting users into
an impossible completion process.

Proposed new records: migration_batches, source_record_mappings,
migration_invitations, user_onboarding and per-record review states. These
are design names, not existing tables.

## Admin initiation and monitoring

Proposed migration screen flow:

1. Load the private CSV and run Preview, with counts of valid rows,
   normalised duplicates, invalid addresses, existing accounts and conflicts.
2. After review and backup, an explicit Import creates only the approved
   user/person pairs and their initial setup states.
3. Preview the invitation wording and recipients. An explicit Send invitations
   action sends a pilot or selected batch, with retry-safe delivery tracking.
4. List accounts by not invited, invited, delivery failed, started, completed
   and needs help. Show the current setup step and last activity.
5. Provide controlled resend and selected follow-up actions. Do not schedule
   automatic reminders or send to all addresses as a side effect of importing.

The onboarding guard applies on every authenticated request, regardless of
whether login used an invitation, password, ordinary magic link or recovery.
A pending imported account goes to its first incomplete setup step instead
of the dashboard. Record progress durably, not only in the session.
Invitation expiry must offer a request-new-link route without allowing
account takeover. Handle wrong-account sessions explicitly.

## Duplicate handling and safe reruns

For the first CSV, match on (event-pal, normalised original email) and retain
the resulting user/person ID mapping. Later sharing and horse files should
use stable source IDs where available. Validate all owner
and recipient references before import. Report created, matched, updated,
incomplete, conflicted and skipped records with source row references.

Do not merge people by name, overwrite accounts simply because emails match,
or attach unresolved ownership to an arbitrary administrator. Duplicate
member numbers, conflicting passports and ownership changes need review.

Reruns must not duplicate records, resend invitations, overwrite user
corrections, reset completed steps or revive revoked shares. Record imported
field provenance and a reviewed update policy.

## Delivery sequence

1. Review the three-column users/people sample, including case-only duplicate
   emails, missing names and a match to an existing account. Resolve household
   cases outside this one-row-per-email format.
2. Build a read-only preview and mapping report. Agree exceptions before import.
3. Build onboarding with synthetic tests covering expiry, single use, email
   scanners, resend, existing accounts, different-account sessions, interrupted
   setup, incomplete records and direct-route enforcement.
4. Back up the target and rehearse the approved import without sending email.
   Reconcile counts, ownership, sharing permissions and rollback behaviour.
5. Review representative accounts, then authorise a pilot invitation send.
6. Keep reusable source changes on development, commit and pull into the target
   with git pull --ff-only. Read docs/DEPLOYMENT.md before deployment.
7. Authorise final import and invitation batches separately and reconcile
   failures, mapping exceptions and completion after each batch.

The immediate next step is the users-and-people export and ownership mapping.
Shares and horses follow once that structure is agreed.
