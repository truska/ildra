# Live setup checklist — recent onboarding and membership changes

Prepared 20 November 2026. This is a preparation checklist, not confirmation of
live state: live has not been inspected or changed. Follow [DEPLOYMENT.md](DEPLOYMENT.md)
for the full deployment baseline. Scope: the recent onboarding, preferences,
membership display and related event/image/admin work.

## Before deployment

- [ ] Confirm the destination hostname, database and release commit. Back up the
  live database, private configuration and uploaded files.
- [ ] Push the approved development commits and deploy using `git pull --ff-only`.
  Do not copy the development database over live or apply reusable source fixes
  directly on live/staging.
- [ ] Include the follow-up receipt change when it has been reviewed and committed;
  it is supplied with this readiness release in `checkout_complete.php`.

## Onboarding rollout: required decision before live invitations

- [ ] Follow [ONBOARDING_LIVE_ROLLOUT.md](ONBOARDING_LIVE_ROLLOUT.md) for the
  agreed populate-first, DNS-cutover, then-invite sequence. Production rollout
  support now uses the destination's private `account-migration.php` opt-in;
  it remains disabled until explicitly configured and enabled.
- [ ] Keep Import workspace, destructive onboarding resets and the per-user
  testing reset restricted to development. Do not copy
  `../private/import-workspace.php` or enable its development reset opt-in on live.
- [ ] For the approved production release, initialize the onboarding schema and
  initial campaign/page-copy rows in the destination after backup. The additive
  schema files are:
  - `migrations/2026-11-17-account-migration-pilot.sql`
  - `migrations/2026-11-17-internal-record-notes.sql`
  - `migrations/2026-11-17-migration-horse-reviews.sql`
  - `migrations/2026-11-17-migration-horse-choices.sql`
  Current development initializes these through `ensureMigrationTables()`;
  use `php scripts/prepare_account_migration.php --check`, then `--apply` after the destination backup. SQL table
  creation alone neither imports accounts nor sends invitations.
- [ ] Agree the final source exports, import mappings, duplicates and user/Person/
  Horse ownership. Store sources outside the public web root; preview and approve
  data operations separately. See [SOURCE_DATA.md](SOURCE_DATA.md). The standalone
  user/horse import scripts are supplied by the later production-readiness release,
  not by `18737f2`; their private CLI opt-in is separate from invitation sending.

## Settings and editable content to enter or transfer deliberately

These values are database content; Git does not transfer development edits.

- [ ] **Admin → Onboarding:** review Welcome heading/message, Password & user
  settings, Person, Horses, Shares, Finish and Thank you. Review the separate
  account-closure explanation and invitation subject/body in Account Migration
  after enabling the production editor for Developer/Superadmin.
- [ ] **Onboarding → Settings:** choose Enable Horses, Enable Shares and the shared
  Show Blank Pages setting. Code defaults are Yes / Yes / No respectively.
- [ ] **Admin → Memberships:** review names, descriptions, costs, publication state,
  eligibility and admin-only status. Descriptions supply Membership Information.
  Set order to Adult 10, Adult 66+ 20, Youth 30, Child 40, Supporter 50.
  Check the Youth name: development currently says **Youth 18 - 22**.
- [ ] Confirm membership purchase/renewal year and the published Horse Logbook
  product's year and price in the destination's settings/catalogue.
- [ ] **Pricing Schemes → Master Ride Classes → Calendar descriptions:** transfer
  PR = Pleasure Ride (PR), VPR = Vetted Pleasure Ride (VPR),
  CTR = Competitive Trail Ride (CTR), ER = Endurance Ride (ER).
- [ ] Review destination admin menu visibility/role permissions for Onboarding and
  the account migration area. Local menu customizations remain destination data.

## Email and payment environment

- [ ] Review the live sender, transport choice, per-email-type routing and private
  SMTP/SES credentials. Check the existing SES setup and delivery using an
  explicitly approved recipient; see [EMAIL_DELIVERY.md](EMAIL_DELIVERY.md).
- [ ] Set **Settings → Campaign Email → Public base URL** to the intended live
  HTTPS origin so scheduled email links are absolute and correct.
- [ ] Explicitly agree when to remove the global test redirect. Development's
  `clients@truska.com` test destination must not unintentionally receive live
  customer mail. Use the private rollout delivery mode and the agreed DNS cutover first.
- [ ] Verify destination campaign sending switches and CLI cron jobs before
  enabling automatic sends. Check authentication/recovery links use the intended
  live domain. Approved auth origins already include enduranceridingireland.com;
  no origin code change is needed for that listed hostname.
- [ ] Verify live Stripe configuration, webhook signing secret/endpoints and
  payment mode using the existing deployment/payment procedure. The receipt
  layout change itself needs no Stripe setting or database migration.
- [ ] Do not assume the uncommitted SES delivery-report/webhook enhancements are
  included in this release. Their rollout is a separate task.

## Filesystem and automatic setup

- [ ] Verify newly deployed PHP/assets are readable by the actual PHP/web user.
  Served files normally use mode 664 on this project; ACLs do not travel in Git.
- [ ] Verify existing image-upload directories are writable by PHP. For Ride
  Report galleries specifically create/check
  `filestore/images/news/{original,lg,md,sm,xs}` and perform the real PHP-user
  write checks in [DEPLOYMENT.md](DEPLOYMENT.md#ride-report-gallery-storage).
- [ ] Verify `/scripts/` remains denied over HTTP; operational jobs use PHP CLI.
- [ ] Open **Admin → Memberships** after deployment. Its shared setup automatically
  creates `membership_types.display_order` and initializes the requested order
  once, provided the database account has the existing schema-change privileges.
  **No separate manual membership-order SQL migration is required.** Later admin
  order edits are retained.
- [ ] No extra manual schema migration is introduced by the unified preference,
  receipt labels, Committee organiser, quarter-hour controls, image-delete buttons
  or sticky sidebar changes. They still depend on the site's existing baseline.

## Final live acceptance

- [ ] Use an approved test account to check invitation → combined password/name →
  People → enabled Horse/Share stages → Finish. Verify resume after sign-out.
- [ ] Check the one Ride Reminders and Updates choice: initially On for first
  onboarding/new registration; an explicit Off choice stays Off and saving sets
  all three existing category flags together. Existing records are not bulk opted in.
- [ ] Check membership order on `/pages/membership`, `/memberships` and dropdowns;
  check Membership Information shows names/descriptions in that same order.
- [ ] Check Buy a Logbook, Logbook not Required, Remove Horse and name-change popup;
  check ownership, basket contents and Next. Completion Close must reach My Account
  without a second Close.
- [ ] Verify basket → checkout → receipt shows the correct member/horse names,
  membership type and prices. Confirm payment/entitlements once through the
  approved payment test procedure; this checklist does not authorize a real charge.
- [ ] Check a test unsubscribe link clears the three optional categories while
  essential entry messages remain separate. Confirm actual email receipt/logs.
- [ ] Retain the release reference, backup locations and acceptance results before
  inviting the real user population.

Receipt verification: `php scripts/tests/checkout_receipt_labels.php` renders the
actual receipt item template with synthetic basket/stored-metadata items; it
does not connect to the database or payment provider.
