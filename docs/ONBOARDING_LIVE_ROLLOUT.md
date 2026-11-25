# Onboarding rollout: populate first, change DNS, then invite

Agreed sequence, 20 November 2026: populate the destination installation while it
is reached as `ildra.witecanvas.com`, then switch to
`enduranceridingireland.com` before sending real migration invitations.
No live database, DNS, email routing or credentials were changed while preparing
this release. Source preparation is separate from actually deploying it.

## 1. Prepare the destination without enabling invitations

Back up the destination database, private configuration and filestore. Deploy the
approved development commits using `git pull --ff-only`; follow DEPLOYMENT.md,
including the gallery storage checks if those features are being deployed.

Copy `config.account-migration.example.php` to
`../private/account-migration.php` **on the destination**. Fill in:

- Actual canonical `web_root` (the result of `realpath()` for that installation).
- Actual `database` name and an existing active Developer/Superadmin's
  `operator_user_id` for command-line imports.
- `host`: `enduranceridingireland.com` and
  `public_origin`: `https://enduranceridingireland.com` for the final rollout.
- `enabled`: **false**. `allow_imports`: **false** initially.
- `delivery_mode`: `redirect` until rehearsal and DNS/TLS checks are complete.

Keep this private file readable by the destination PHP/CLI account, outside the
web root, with local private-file permissions. Do not copy the development
`import-workspace.php` opt-in. Do not copy the development database over live.

Run the additive schema check from the destination web directory:

```bash
php scripts/prepare_account_migration.php --check
```

Confirm its destination/database output. After the destination backup, prepare
schema and initial draft rows:

```bash
php scripts/prepare_account_migration.php --apply
```

This prepares authentication and onboarding dependencies, campaign/record-note/
horse-review tables and initial draft rows. It does not import or enroll accounts,
change passwords, send email or enable the migration admin screen. Existing
baseline site schema is still required.

## 2. Populate and review imports

Put the final approved exports in the destination's `../private/source-data/`.
Read SOURCE_DATA.md and IMPORT_WORKSPACE.md first. The account importer expects
email/first-name/surname CSV columns; the horse importer expects Horse name and
Email address. These scripts are not a general importer for every old-system
field or financial history. Opening balances use their separate reviewed process.

Set `allow_imports` to **true** temporarily. `enabled` remains **false**. Create
`../private/import-workspace-backups/` with private permissions and verify that
the command-line operator can write there. Actual imports create full private
backups and receipt/mapping files. Preserve these with the source exports.

Preview, review conflicts/ownership and obtain approval of the proposed data
operations before using --apply:

```bash
php scripts/import_user_people.php ../private/source-data/approved-users.csv
php scripts/import_horses.php ../private/source-data/approved-horses.csv
```

Apply the approved users/People import before horses, then preview again to check
stable matching. File receipt/mapping checks protect horse reruns; existing
accounts are preserved rather than overwritten. Imported accounts are not emailed
or forced into onboarding merely by importing them. Set `allow_imports` back to
**false** after the final validated import. Do not use development reset tools on
production data. Development import/onboarding reset tools stay blocked outside
development. The agreed pre-launch Test Data Reset has its own narrowly pinned
[private opt-in and mandatory retirement](PRELAUNCH_TESTING_RESET.md).

## 3. Review destination settings and content

Complete LIVE_SETUP_CHECKLIST.md. In particular review:

- Membership names, descriptions, order, prices, eligibility and purchase year;
  published Horse Logbook year/price.
- Admin → Onboarding wording, Welcome heading/message, optional-page switches.
- Private SMTP/SES credentials, sender, per-type email routing, Stripe settings,
  and the email/booking acceptance checks.
- Invitation draft and closure explanation once the migration admin screen is
  enabled. No invitations are sent by saving a draft or opening the screen.

A rehearsal on `ildra.witecanvas.com` is supported if needed: change both private
`host` and `public_origin` to that exact hostname/HTTPS origin, use
`delivery_mode=redirect`, enable the global email test redirect to an approved
address, and then explicitly set `enabled=true`. Migration admin and sending
require an active Developer or Superadmin; ordinary Admin/Manager cannot send.
Test only the approved accounts. Disable the rollout again before changing host.
Do not send real-user batches until the final domain is verified.

## 4. DNS cutover and final invitation enablement

Before enabling direct sending:

1. Confirm final DNS and a valid HTTPS certificate for
   `enduranceridingireland.com`; verify public/account/password-recovery URLs.
2. Set private `host`/`public_origin` to the final values above. The exact installed
   root/database must still match. Do not enable development workspace resets.
3. Set Campaign Email Public base URL to `https://enduranceridingireland.com`.
   Confirm sender identity/DKIM and destination delivery settings. Review cron
   sending switches before enabling automated mail.
4. Explicitly disable the global email test redirect, and set private
   `delivery_mode` to **direct**, only when ready to deliver to intended users.
   The application blocks a mismatch: it never silently converts a direct batch
   into a redirected batch or a redirected rehearsal into direct delivery.
5. Set private `enabled` to **true**. Keep `allow_imports=false`.
6. As Developer/Superadmin open Emails → Account migration, save/review draft and
   wording, select approved accounts and Preview. The UI explicitly states direct
   delivery and lists intended recipients. Check personal links use the final
   domain. Then use the explicit Send button for the reviewed batch.

A send preview expires after 15 minutes and pins the operator, campaign revision,
recipient snapshots and delivery target/mode. Changing redirect settings requires
a new preview. The email sender rechecks the target/mode immediately before
transport. Invite links remain single-use and account-bound, expiring in seven
days. Saving/importing/configuring never sends mail on its own.

## Separate email reporting enhancement

Core SES sending is already committed (`a4ff24a`). The separately committed
`email_delivery_status.php`, `email_ses_feedback.php`, `email_ses_webhook.php`,
admin panels and associated migration/tests add acceptance/delivery/bounce status
and signed SNS feedback. They are not required for onboarding invitation delivery
and were committed separately from the production-readiness change. Review
EMAIL_DELIVERY_STATUS.md separately before their AWS/SNS rollout.

## Verification performed on development

Offline destination/role/redirect-policy tests, send-time mode checks, receipt
rendering, and temporary-table invitation/onboarding/import regression checks.
No real import, payment, email delivery or destination configuration change was
performed. Destination acceptance, DNS/TLS and an explicitly approved delivery
check remain manual cutover steps.
