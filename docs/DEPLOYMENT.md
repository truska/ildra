# Deployment checklist

## Legacy opening balances (credits and debits)

After an environment database backup, deploy the development commits with `git pull --ff-only`. Apply `migrations/2026-10-23-legacy-opening-balances.sql` to that environment's website database, or open **Legacy Opening Balances** as a finance administrator: the page applies that same idempotent table setup. The schema adds a pending-balance register, import batches and email-ownership confirmations. It does not modify user accounts or balances. Keep source-code fixes on development; destination environments receive only deployment and environment-specific data operations.

Copy the approved CSV privately to `../private/source-data/ildra-credits.csv` on the destination. It must have semicolon-delimited `id;email;name;balance` columns. Review the complete preview on **Legacy Opening Balances**, confirm the sign convention, and use **Import reviewed opening balances** only after the backup and review. Original negative balances become positive GBP credit; original positive balances become debit. The supplied development sample has 41 rows: £803 credit, £51 debit, net £752 credit. Do not treat this sample as a production cutover snapshot without obtaining a final approved export. Keep the original unchanged; use separate synthetic fixtures for tests.

Check readability as the destination PHP user: it needs read access to this one CSV and the migration SQL file, and traversal of their parent folders. Preserve the private source folder's restrictions; do not grant directory listing or inherited access to other uploads. Development uses a named ACL for web7 granting traversal only on `private/source-data` and `web/migrations`, and read-only access to the specific CSV and new SQL file. ACLs are environment-specific and are not preserved by Git. New PHP/view files use mode 664. Development's HTTP check confirmed the CSV is readable but not writable by PHP and the source directory cannot be listed.

Importing creates pending records only. Source system plus source ID identify each balance; repeat imports are unchanged and changed values for an existing source record are rejected without a partial import. Duplicate emails/IDs or malformed money values reject the file. The reviewed file hash is checked again when submitting the import. The original addresses and names remain private and are not committed to Git.

The check runs at normal password/authenticator/magic-link login, and is repeated before checkout calculates amounts. Signup itself does not send a migration email or allocate balances. For a matching pending balance, the account page requests email ownership confirmation using a one-time, account-and-email-bound link valid for 24 hours. The user must sign in to the same account; changing the email, using another account, expired links and replayed tokens cannot claim balances. Approved link origins cover development, staging and the live ILDRA domain (plus localhost for tests). Account impersonation cannot claim a user's balance. Existing signed-in accounts imported later are caught by the checkout fallback, with their basket retained.

After confirmation, each balance is allocated once, atomically with its `manual_credit` or `manual_debit` ledger transaction and the account-balance update. A debit reduces existing credit and is carried into the normal checkout amount due. Missing confirmation blocks checkout when there is a matching pending balance. Pending verification and allocation failures leave the balance for retry or administrator review. No Stripe payout or new Stripe receipt is created by an opening balance.

The admin register shows every pending/allocated balance, its original email/name, credit/debit amount, recipient, allocating administrator or verified-email method, date and finance transaction. Admin/Superadmin/Developer roles may view it; imports and manual allocations also require `finance.adjust_balance`. Manual allocation can select a different active account after ownership has been checked. Already allocated balances cannot be assigned again or moved to another account through this page. If a correction is needed, preserve the audit trail and use the normal controlled finance adjustment process.

The recipient sees a popup listing the transferred credit/debit on their next account visit. Acknowledgement is recorded for that recipient and those rows only; unacknowledged notices persist. Test-data reset includes these migration records and confirmations alongside finance records, so a reset requires reimporting the pending register; the private CSV is retained. Do not use the reset tool on real balances.

Verify in the destination with synthetic/test data: mixed-case email matching, a credit, a debit, an already registered account, a changed email, manual allocation to a different active account, replayed imports/claims, popup acknowledgement and checkout totals. Perform cutover before opening checkout so no pre-migration Stripe Checkout sessions remain outstanding. No production source CSV or real account balance is imported by automated tests:

```bash
php scripts/test_legacy_balances.php
node scripts/tests/legacy_balances.cjs
```

The PHP test redirects table setup into session-only MariaDB temporary tables, uses synthetic addresses, and sends no email. Browser checks use fixture HTML only. A signed-in confirmation email and checkout smoke test remains a destination verification step.

## Stripe Fulfilment

All collection tools now live at **Stripe Fulfilment** (`admin/stripe_fulfilment.php`), with its own admin menu entry. Its three collection tabs are **Events**, **Memberships & Logbooks**, and **Miscellaneous**; **Reconciliation** contains missing fees, unallocated receipts and a read-only recent Stripe activity comparison. Finance retains the transaction ledger, entry revenue report, account credits/balances and Payment Request creation/tracking. Old Finance Events/Memberships links redirect to the new page, and existing open collection forms are handled there.

No new schema migration or upload folders are required. After backing up the destination, pull the development commits with `git pull --ff-only`. Menu defaults insert the new system link on the next normal admin load without overwriting existing menu customizations. Default page access is Admin/Superadmin/Developer; collection and fee reconciliation use the existing `finance.create_payout` action permission. Collections remain **Stripe test-mode only** across all three groups.

Paid Payment Requests with an event/entry link increase that event's collectible receipts; requests without an event link go into Miscellaneous by payment transaction month in Europe/London. They are never also placed in Memberships & Logbooks. Mixed checkout receipts and fees are split in pennies across every event and product group, conserving the total exactly. Missing fees, duplicate receipt references and ambiguous refunds block affected collections. Existing event payouts continue to reduce their event balance; Refresh status can check these payouts without creating a new payout.

New Payment Request completions retrieve fee metadata when available. A browser return and webhook arriving together lock the request and atomically save one ledger receipt plus its paid state. Amount and currency must match the request. When fees are unavailable, the receipt is still recorded with an unknown fee and can be completed later using **Reconciliation → Retrieve Stripe fee**. Existing paid requests can use the same fee-retrieval action; do not assume their missing fees are zero. A failed receipt save returns a webhook error so Stripe can retry.

Reconciliation's activity check reads the latest 100 Stripe balance transactions and compares charge/payout/refund references with the local ledger. Unmatched entries, Dashboard payouts, disputes and other adjustments require review; this comparison does not automatically import them or constitute a full historical reconciliation. It does not move money. Fees must match the original receipt's amount, currency and Stripe mode before they are saved. Stripe references: [expanded responses](https://docs.stripe.com/api/expanding_objects), [balance transactions](https://docs.stripe.com/api/balance_transactions/list), [idempotent requests](https://docs.stripe.com/api/idempotent_requests).

Verify all three collection tabs, the menu link and role restrictions, a linked and an unlinked Payment Request, fee retrieval for an older paid request, partial collections, failure/status refresh, prior collection history and the old Finance redirects. Check event entry detail still opens and includes Payment Request receipts. Signed-in Stripe test-mode verification remains required on the destination; development's automated tests mock Stripe and make no payouts.

```bash
php scripts/test_stripe_fulfilment.php
php scripts/test_stripe_fulfilment.php --mysql
php scripts/tests/misc_payment_receipts.php
node scripts/tests/finance_monthly.cjs
```

MariaDB tests use session-only temporary tables; no persistent test receipts or payouts are created.

## Monthly Memberships & Logbooks collections

Stripe Fulfilment has a **Memberships & Logbooks** tab. Months follow the original `payment_stripe` transaction timestamp converted to Europe/London, never the membership/logbook product year. Membership and logbook receipts share one monthly balance, with separate subtotals. Booking items allocated to events remain in the Events report. Mixed-basket payments and Stripe fees are allocated proportionally in pennies. Internal checkout debits, simulated payments and credit-only purchases do not create collectible Stripe funds.

No migration or new upload directories are required. After the normal environment backup, pull the committed source with `git pull --ff-only`. Existing `finance_transactions` store monthly payouts with `metadata.collection_scope=memberships_logbooks` and `metadata.collection_month=YYYY-MM`. These payouts appear with their month in the main Finance transaction list and are not event payouts. Collection uses the existing `finance.create_payout` permission and remains restricted to Stripe test keys; no live-money collection has been enabled.

Verify a December payment for a following-year product appears under December. Check a mixed membership/logbook/event booking, fees, a partial collection, its remaining balance, a second collection, and the month detail's transaction and collection history. Fully collected months are hidden by default; **Include fully collected months** restores them. Older months can still be collected. Later linked refunds/credits reduce the original payment month, and a negative balance remains visible as a shortfall. Refunds must identify a booking item or an unambiguous original booking; refunds involving multiple possible payments block collection pending reconciliation. Unallocated standalone account adjustments are not guessed into a month. Missing Stripe fees also block collection rather than assuming zero.

The Collect dialog checks Stripe's available card balance; the POST checks it and the monthly remaining balance again under a database advisory lock. Each request reserves its amount in the ledger before calling Stripe. Pending/paid payouts count as collected; a failed/cancelled payout releases its balance when **Refresh status** confirms that result. For a timeout or uncertain result, the amount stays reserved: use **Confirm collection** in that month's history to retry with the persisted Stripe idempotency key and original parameters. Unconfirmed requests older than 23 hours require reconciliation against Stripe before any manual correction; do not resend them with a new key or delete their reservation without checking Stripe. This avoids duplicate payouts after Stripe's documented idempotency retention period. See [Stripe's idempotency guidance](https://docs.stripe.com/api/idempotent_requests).

Development verification (mocked Stripe responses; no external payouts):

```bash
php scripts/test_finance_monthly.php
php scripts/test_finance_monthly.php --mysql
node scripts/tests/finance_monthly.cjs
```

The default PHP test uses PDO SQLite; `--mysql` uses session-only temporary tables in the configured development CLI database. The browser test requires Playwright/Chromium and uses generated fixture HTML and mocked balance responses. A signed-in end-to-end Stripe test collection remains a deployment verification step.

## Event-linked account adjustments

The event finance detail and summary include both `manual_credit` and `manual_debit` adjustments linked by `metadata.event_id`. The signed amount controls their effect: a positive account credit reduces event revenue; a negative credit reversal restores it. Existing records with contradictory type labels, including T-77 (positive £2, `manual_debit`, event 36), require no database correction. Refund transactions retain their existing absolute-value treatment. New adjustments normalize manual type labels to the selected direction.

Pull the committed source with `git pull --ff-only` using the usual environment backup procedure; no migration or Stripe operation is required. Clear finance page filters and verify T-77 appears under event 36 with a -£2 event movement, zero Stripe fee, and the account holder's name. Confirm the event list and detail agree on refunds/credits and revenue. Run `php scripts/test_finance_event_adjustments.php` on development (requires PDO SQLite).

## Retire the test-data reset tool at launch

After the final agreed pre-launch cleanup, remove the test-data reset tool from production before accepting real memberships or payments. Remove `admin/testing_reset.php`, its shared `testing_reset.php` implementation, and its admin navigation entry through a source change made and committed on development, then deploy that change. Keep private reset backups outside the web root. Verify that the production reset URL is no longer accessible.

## Ride Report gallery storage

Git carries the gallery directory skeleton, but Git does not preserve the writable directory permissions required by PHP. Before enabling Ride Report galleries, resolve and verify the live web root, then create `filestore/images/news/{original,lg,md,sm,xs}` and apply the live server's shared-write policy.

```bash
ILDRA_WEB_ROOT=/absolute/path/to/live/web
mkdir -p "$ILDRA_WEB_ROOT/filestore/images/news/"{original,lg,md,sm,xs}
chmod 2777 "$ILDRA_WEB_ROOT/filestore/images/news" "$ILDRA_WEB_ROOT/filestore/images/news/"{original,lg,md,sm,xs}
ls -ld "$ILDRA_WEB_ROOT/filestore/images/news" "$ILDRA_WEB_ROOT/filestore/images/news/"{original,lg,md,sm,xs}
for size in original lg md sm xs; do
  test -w "$ILDRA_WEB_ROOT/filestore/images/news/$size" || exit 1
done
```

If the live server uses a more restrictive web-user ACL policy, apply that equivalent ACL instead. Finally, upload multiple images through a Ride Report's **Ride Gallery** screen and confirm every generated size is created.

## Multiple Manual and Super announcements

After backing up the live database and pulling the development source with `git pull --ff-only`, run from the live web root:

```bash
php scripts/migrate_announcements.php
```

This creates the shared `announcements` table and copies the existing Manual and Super notices from `site_settings`. It is safe to rerun: previously imported notices are not overwritten. Use this PHP command rather than applying only the table SQL, which does not import existing content. The management page also runs this idempotent setup when first opened. No new image upload directories are required; announcements continue to use the asset library.

Verify both tabs under Announcements, the imported HTML/images/colours/dates, and the public Manual bar and Super dialog. Enabled notices with inclusive Europe/London dates qualify; within each type, lowest display order wins, then latest start date, then newest ID. Manual and Super may display together. Automatic event notices retain their existing rules. Super dismissals are independent for new records; existing imported notices retain their previous dismissal token.


## Horse registration, logbooks and receipt/header updates (22 October 2026)

Deploy the committed development source with `git pull --ff-only` after backing up the live database. Before accepting registrations, run these migrations against that environment's database, in this order:

1. `migrations/2026-10-22-horse-registration.sql`
2. `migrations/2026-10-22-third-party-events.sql`

Both migrations are safe to rerun on this deployment's MariaDB database. They add nullable `horses.owner_name VARCHAR(255)` and `events.external_entry_url VARCHAR(2048)` columns, and insert the **None** and **Other** horse qualification choices without changing existing IDs. There are no column removals or destructive updates. Existing date-of-birth, passport-issuer and vaccination values remain stored even though those inputs are no longer displayed in the new horse forms. Existing horses with no owner name need that value entered when their full details are next saved; do not infer horse ownership from the account holder.

Verify the schema and qualification choices after applying the migrations:

```sql
SHOW COLUMNS FROM horses LIKE 'owner_name';
SHOW COLUMNS FROM events LIKE 'external_entry_url';
SELECT id, name FROM horse_qualifications WHERE name IN ('None', 'Other');
```

The application also performs these additive upgrades on first use, but explicitly applying and checking the migrations makes the live rollout predictable. No database changes are needed for the receipt labels or yellow Membership/Logbooks heading buttons. These migrations do not copy development accounts, purchases, horses, events or images to live.

Check Add Horse with name only, the Yes/No logbook choice, full mandatory logbook/Edit Horse details, owner-name persistence, and the normal basket route. Check the new total wording in both HTML and plain-text receipt previews without sending test messages to real users. Confirm heading buttons use the shared yellow style and remain absent when the title banner is hidden.

## 3rd Party Events

Pull the development source before enabling the new Events tab. The event loader adds the nullable `events.external_entry_url` column on first use; `migrations/2026-10-22-third-party-events.sql` provides the equivalent explicit migration. Back up the database before applying schema changes in another environment.

Third-party event images reuse `filestore/images/events/{original,lg,sm,xs}`. Verify these live directories exist, follow the environment's shared-write policy, and accept an image upload from **Events → 3rd Party Events**. Check that a published item appears in calendar date order, its entry button opens the external organiser's website in a new tab, and no ILDRA entry form is displayed. Draft items must remain hidden from visitors.

## Stripe webhook addresses for development, staging and live

Checkout return URLs already follow the hostname used for that environment. **Finance → Payment requests → Stripe webhook setup for this site** also generates the webhook address from the current hostname, using its public standard HTTPS address rather than a local forwarded browser port.

Register the corresponding explicit URL in Stripe; Stripe does not discover a changed hostname from deployed PHP:

| Environment | Webhook URL | Stripe mode |
| --- | --- | --- |
| Development | `https://dev-ildra.witecanvas.com/stripe_misc_webhook.php` | Test |
| Staging | `https://ildra.witecanvas.com/stripe_misc_webhook.php` | Test |
| Live | `https://enduranceridingireland.com/stripe_misc_webhook.php` | Live |

Use `checkout.session.completed` snapshot events. Each environment must have the appropriate Stripe API keys and that endpoint's matching `webhook_secret` in its private Stripe configuration. Keep secrets out of Git. The endpoint handler itself contains no hard-coded hostname. Registering an additional endpoint produces a different signing secret; changing only an existing endpoint's URL preserves its existing secret.

On 22 October 2026, the existing ILDRA test endpoint's incorrect `ildra-witecanvas.com` URL was corrected to `ildra.witecanvas.com`. Its subscription and enabled status were retained; live-mode configuration was not changed. In Stripe's test Dashboard, resend a failed delivery and verify a `2xx` response and that the corresponding miscellaneous payment request is recorded once. An unsigned browser or HEAD request returning `400 Invalid signature` is expected and establishes reachability only, not successful signature verification or payment processing.

When launching the final domain, configure and verify the live-mode endpoint separately. Do not resend live-mode payments into development or staging, or route test payments to the live database. No database migration is needed for these URL changes.

## Screen Guidance (extended Account Help editor)

The existing `/admin/account_intros.php` page is now labelled **Screen Guidance**. It keeps the same permissions, menu identity and `account_intro_modals` records. The first phase groups existing popups into Accounts, Horses & Logbooks, and Email Consent, and adds editable Add/Edit Horse introductions, the saved-horse confirmation, the full logbook form introduction, and the About logbooks panel. Other audited guidance can be added to this same editor later.

No schema change or separate SQL migration is required. After backing up and pulling the development source with `git pull --ff-only`, open this signed-in admin page in the destination environment. Missing defaults are inserted without overwriting any existing guidance. This browser route uses the environment's normal web database configuration, avoiding the older CLI/local-database configuration issue.

Check that the existing People/Horses/Shares/My Account and consent popups retain their previous text and visibility. Under **Horses & Logbooks**, edit About logbooks and confirm it appears beside the choice after creating a horse. Check Add/Edit Horse and the full logbook form too. Each expanded item has its own Save button. Saving an item must not change any other item's text or visibility, and should return to the same tab with that item expanded. Turning off About logbooks hides its panel and lets the left-hand content use the full width. Prices, form validation, eligibility and the purchase flow are unchanged. Product descriptions remain managed under Memberships; they are not duplicated in Screen Guidance.

## Purchasing maintenance switch

Deploy the committed development source using the normal database backup and `git pull --ff-only` procedure. No schema migration or upload folders are required. Purchasing remains enabled by default; deploying this change does not activate maintenance.

Under **Settings → Purchasing**, Admin, Superadmin or Developer can enable **Pause Purchasing** and edit its plain-text public announcement. The announcement displays across public pages while paused. Users below Admin (including Manager and Organiser) and guests cannot start basket checkout or follow the website's miscellaneous payment-request links. Credit-only and zero-cost basket checkouts are blocked too. Admin, Superadmin and Developer retain checkout access; impersonation uses the impersonated user's role.

Basket expiry uses an active-time clock which excludes maintenance intervals. Existing saved baskets, new baskets added during a pause and offline baskets retain their remaining active time. Timers pause for administrators too. Refresh an already open basket after maintenance to resume its countdown. Older browser countdowns cannot expire a basket before the server's active-time deadline. Pausing does not restore baskets that expired before maintenance. Basket preservation is not a capacity reservation or a guarantee that event conditions or prices remain unchanged; the existing checkout validations continue to apply.

New and reminder payment-request emails link through `misc_payment.php`, which checks the switch before redirecting to Stripe. Already emailed direct Stripe URLs and Stripe sessions already open remain usable until Stripe expires them. This switch does not revoke hosted Stripe sessions, stop existing payments being recorded, suspend webhooks, freeze automatic opening-balance allocation, or disable administrator finance operations. Before changing Stripe keys/mode, review and expire or wait for outstanding sessions in the original Stripe mode, and reconcile completed payments. Do not use the pause switch as evidence that no payments are in flight.

Check as User, Manager, Organiser and Admin: direct GET/POST checkout, credit/free checkout, payment-request links, announcement escaping, and pause/resume of an existing basket across logout/revisit and repeated pauses. Check that already-paid completion callbacks still record receipts. No automated test sends email or calls Stripe:

```bash
php scripts/test_purchasing_pause.php
php scripts/test_purchasing_pause.php --mysql
```

The MySQL test shadows the settings and basket tables with session-only temporary tables; it changes no persistent settings or baskets. Basket timestamps now exclude the cumulative paused seconds in `site_settings`. If rolling back this implementation after maintenance has been used, first resume purchasing and translate saved basket timestamps back by adding `purchasing_paused_seconds`; otherwise older expiry code may immediately remove them. Do not reset the cumulative setting independently of stored baskets.

## Master ride classes and scheme selections (10 November 2026)

Read [MASTER_RIDE_PRICING.md](MASTER_RIDE_PRICING.md). Back up the destination database and pause purchasing/admin event editing for the rollout. Deploy the tested development commit using `git pull --ff-only`. Do **not** copy development's database or replay its development-only pricing refresh on live. The live scheme rows are the migration input.

Identify the authoritative all-classes Ride scheme (the supplied live export uses ID 1, **Ride Pricing All Classes**). From the destination web root, first run a read-only preview:

```bash
php scripts/migrate_ride_pricing.php --scheme-id=1
```

Review the 17 master classes, all mapped schemes, reported price changes and the printed preview SHA. The supplied live configuration gives 2 ride schemes and 21 selections. The PR-only Junior Member price becomes the master £15 instead of £13. Confirm the current live data still has the expected classes and rates; fresh live edits may change the preview. An ambiguous class stops the migration for review rather than creating a guessed mapping. Source-code corrections must be made and committed on development first.

After confirming the backup and preview, apply using **that destination's printed SHA**:

```bash
php scripts/migrate_ride_pricing.php --scheme-id=1 --apply --expect-sha=PASTE_DESTINATION_PREVIEW_SHA
```

This creates four tables and seeds the master and scheme selections atomically. Pricing protection is calculated from current entry windows and recorded booking items; the legacy lock table is retained only for compatibility. It preserves existing event price rows, event records, bookings and payments. Schema DDL is outside the data transaction; a failed apply may leave empty new tables, but no partial master data is committed. Running only the SQL file does not seed or activate master pricing. Re-running the PHP migration after success does not overwrite later price edits. No upload folders or private source CSVs are needed on the destination for this migration.

Verify **Pricing Schemes → Master Ride Classes & Prices**, both Ride scheme selections, the PR Junior Member standard rate, the **Ride prices** content element, and non-ride schemes. Test a draft's explicit template reapply and an opened event's locked prices and unchanged row IDs. Changes to master prices must not alter any existing event automatically. New and copied rides must use the master rates and eligibility; unmatched historic classes should require a current scheme rather than copy obsolete prices. Resume purchasing only when destination checks are complete. Applying the migration does not itself toggle the purchasing switch.

New served PHP files use mode 664 and must be readable by the destination PHP user. Confirm `ride_pricing.php`, `ride_pricing_migration.php`, `admin/ride_classes.php`, `admin/ride_scheme_form.php`, and the CLI migration/SQL file can be read in their intended contexts. Rollback requires restoring the backed-up pricing configuration and deploying the previous source together; do not remove the activation marker while leaving new master edits in place, because old scheme-local prices are retained as a historical snapshot and may be stale.

### Revised event pricing protection

Deploy the revised source normally; no additional database migration is required for an installation where master pricing is already active. Old `event_pricing_locks` rows are ignored and can be left in place. Verify: an open event is protected; a closed event with no received entries can explicitly reapply a scheme; future opening dates or Draft status restore editing only without recorded entries; any received entry, including a withdrawn/cancelled one, keeps pricing protected. Late entries protect rates only during their actual window, not before normal opening. Save schedule/status changes and reload before reapplying. No event rates change automatically.

## Announcement device hiding

Run `php scripts/migrate_announcements.php` for the independent `allow_hide` column before serving updated announcement code. Existing Manual notices default to Force Show; existing Super notices retain Allow Hide. Enable and inclusive dates still determine eligibility. Each notice editor and list uses Force Show / Allow Hide independently of Enable. Settings → Global configures Allow Hide durations (1–365 days), defaulting to 7 days for Announcement and 14 days for Super Announcement.

Verify both types with Force Show after previously hiding the notice: saved browser choices must be ignored and no Hide button offered. With Allow Hide, the expanded manual notice and Super dialog offer the configured Hide for N days button. Hiding affects only that browser/device and notice, including other open tabs, and expires after the chosen duration. Super Close dismisses the current dialog only; it appears again on the next page. Changing dates or resetting dismissals invalidates the saved choice. New notices have independent storage keys. No upload directories are needed.

Development checks: `php scripts/test_announcements.php` and `node scripts/tests/announcement_hiding.cjs` (executes the public dismissal scripts with browser storage fixtures).

## Pages Delete archives records

Pages → Delete now sets `pages.is_archived=1`, retaining the original page, sections, image associations and destination references for future recovery. The Pages list button, confirmation and success message say Archive. Archived pages are absent from normal admin lists, destination selectors, direct editors and public/admin-preview lookups. The home page is protected server-side. A stale page editor cannot save an archived page. The Archives admin tool now provides page-specific draft recovery and guarded purging; other data types remain future work. Archived slugs remain reserved until their page is permanently purged.

After the usual database backup and development-source deployment, the additive `is_archived` column is created automatically on the first page list/lookup. No records are archived by the schema upgrade itself. Empty published-page queries no longer re-seed default content; initial defaults are created only when the entire pages table is empty. Verify Delete with a disposable page, its disappearance from the list and menus, direct editor/preview rejection, and preservation of the database row and related content. Do not test by deleting a real page. Development regression command: `php scripts/tests/page_archiving.php`; it uses session-only temporary tables and does not alter persistent pages. Live/development behavioural differences still require a separate source/schema comparison in the destination environment.

## Archives administration — Pages

The new **Archives** menu item is appended to the admin menu, with a **Pages** tab initially. Admin, Superadmin and Developer can view and act on this screen; direct URL access is checked independently of the menu. Existing menu customization is preserved. Future data types need their own handlers and tabs; there is no request-driven generic table deletion. Bulk actions are deferred.

Restore as Draft clears the page archive flag and sets it unpublished, preserving content, image associations, menu placement and slug. Invalid/unavailable alias destinations block restoration until reviewed. Permanently Delete requires the shared confirmation modal and rechecks archive state and dependencies in a database transaction. Active pages and the home page cannot be purged. Any incoming page-destination reference (including from an archived page) blocks purge. Uploaded page, banner or section images also block purge: restore as draft, review/remove the images through their existing editors, then archive again. Library assets and files are never implicitly removed by this tool. Eligible purge removes the archived page, its content sections and empty owned media containers. Failure rolls back database changes.

No new upload directory or schema migration is required beyond the existing Pages archive column. The archive route performs existing additive table setup before transactions. Verify new files `archives.php` and `admin/archives.php` are readable by PHP (mode 664), the menu and role restrictions, empty list/filtering, cancellation of both confirmation modals, restore returning an unpublished page to Pages, and permanently deleting a disposable image-free/unreferenced archived page. Repeated or stale requests must fail safely. Regression test: `php scripts/tests/page_archiving.php` uses temporary tables and never purges real pages.

Browser fixture check: `node scripts/tests/archives_confirmation.cjs` verifies the actual Archives form markup with the shared confirmation handler; cancellation submits nothing and confirmation submits once with the page ID, action and CSRF token retained. It does not contact the application or alter records.

## Archives administration — Announcements

Archives now appends an **Announcements** tab after Pages, combining Manual and Super notices with a type filter. Normal Manual/Super lists offer **Archive**; archived records are absent from those lists, direct editors and public selection. Disabled/expired/device-hidden notices are not automatically archived. Automatic event notices are generated from events and are outside this archive tab.

Restore as Draft clears the archive flag and disables the notice while preserving its content, dates, library image, dismissal revision and display behaviour. Permanently Delete requires confirmation, checks the archived state under a transaction/row lock and removes only that announcement. Shared library assets are retained. Purging an imported legacy notice records a per-type `announcement_legacy_purged_*` setting so old site settings cannot re-import it; do not remove those markers as routine settings cleanup.

The announcement management screen, Archives announcement tab and `php scripts/migrate_announcements.php` perform the additive `announcements.is_archived` setup before actions. After the usual destination database backup and committed-source deployment, run that migration or visit the management screen. No existing notice is automatically archived. Verify both types with disposable notices, including old direct editor URLs after archiving, disabled restoration, retained library assets, cancellation and repeated/stale requests. Regression checks: `php scripts/tests/page_archiving.php`, `php scripts/test_announcements.php`, `node scripts/tests/archives_confirmation.cjs`, and `node scripts/tests/archives_confirmation.cjs --announcements`. The tests use temporary database fixtures and browser fixture HTML; no real notices are archived/purged. The new served partial `admin/archive_announcements.php` must be mode 664 and readable by PHP; direct requests to this partial return 404.

## Delayed late-entry reopening

Settings → Events adds **Reopen late entries after** (1–8760 hours, default 6). The event editor adds editable **Reopen late entries date/time**, initialised from normal closing plus the configured elapsed hours and stored as `events.late_entry_open_at`. Saved reopening dates remain unchanged when defaults change. New-event automatic dates track normal closing until manually overridden; existing stored reopening dates are preserved. The editor uses Europe/London even on devices configured for another timezone.

The nullable column is added by the established `ensureEntryWindowColumns()` setup when saving/copying an event. No manual SQL is required. Deploy committed development source normally after the destination backup; no bulk event rewrite or scheduled task is needed. Existing records without a stored value use normal closing plus six hours at runtime until reviewed/saved. Review existing enabled late-entry events: if late closing falls before the new reopening time, they have no late window and require an explicit schedule correction before saving with late entries enabled. A global default edit never shifts already stored reopening dates.

Normal entries close exactly at normal closing. During the gap the public entry form is unavailable and shows the reopening date/time. Late entry is offered at reopening only when enabled, published and not full, and ends exactly at late closing. Both public entry GET/POST use the common late-window rule. Calendar/footer labels reflect the late window. Pricing protection reflects the closed gap for events without entries; recorded entries retain protection. Existing basket checkout/payment-completion and administrator preview/manual entry policies are unchanged.

Verify a disposable event before closing, exactly at closing, during the gap, at reopening and at late closing; disabled/full events; a manually overridden reopening; and create/copy/save with changed global defaults. No new upload directories are needed. New served asset `admin/event_late_entries.js` must be mode 664 and readable by PHP/web. Regression commands: `php scripts/test_late_entry_reopening.php`, `php scripts/test_master_ride_pricing.php` (temporary tables only), and `node scripts/tests/late_entry_reopening.cjs` (browser fixture only).

### Five-minute event time controls

Settings → Events and Event Edit time inputs now use hour/minute selectors, with minute choices 00,05,…55. `admin/five_minute_times.js` retains the original named inputs for saving and synchronises generated schedule values and manual reopening overrides. Existing non-five-minute values are preserved as an explicitly labelled existing option until edited, rather than silently rounded. Without JavaScript the native inputs retain a five-minute step. No schema change is needed. Verify new script readability (mode 664). Browser fixture check: `node scripts/tests/five_minute_times.cjs` covers posted values, blank optional fields, historic values and reopening calculation/override integration. A saved reopening override applies during the closed gap, subject to the existing enabled/capacity/date-window checks.

## Persistent authentication and email-request throttling (12 November 2026)

Deploy the development commit after the destination database backup using `git pull --ff-only`; do not amend reusable source on staging/live. Run from that environment's web root:

```bash
php scripts/migrate_auth_throttle.php
```

Equivalent additive SQL: `migrations/2026-11-12-auth-throttle.sql`. It creates only the InnoDB `auth_throttle` table, with an expiry index and a composite scope/subject key. No existing user, password, authenticator secret or login token is changed. The login/request handlers also perform idempotent table setup before transactions, but running the CLI first confirms permissions and makes deployment predictable. New served PHP source `auth_throttle.php` and the CLI script use mode 664 and must be readable in their intended contexts. The unused `scripts/backfill_non_member_entry_open.php` has been removed after a repository reference search; deployment must remove the tracked file, not leave an obsolete copy in the web root.

Limits are defined centrally in `authThrottlePolicy()` in `auth_throttle.php`, ready for a future Settings adapter:

| Operation | Email/identity budget | IP budget | Recovery |
| --- | --- | --- | --- |
| Password and authenticator-code login, combined | 5 failures in 15 minutes | 5 failures in 15 minutes | 15-minute cooldown after the fifth failure |
| Password-reset email requests | 1 request per minute | 5 requests in 15 minutes | 1-minute recipient cooldown; 15-minute IP cooldown |
| Magic-link email requests | 1 request per minute | 5 requests in 15 minutes | Independent from reset requests, with the same durations |

The fifth login attempt is checked normally: a correct credential can succeed, while the fifth failure immediately starts cooldown and displays retry guidance. Blocked attempts cannot verify credentials or extend the cooldown. A successful credential resets the email counter but does not erase that IP's failures against other identities. Successful authentication remains blocked during an active cooldown; normal login resumes automatically at expiry. No permanent account lock or user-status change is introduced. Password and authenticator login share one failure budget, so switching methods, sessions, browsers or IPs cannot bypass the email limit; rotating emails cannot bypass the IP limit. Database row locks cover credential verification and counter updates; email budgets are reserved atomically before account lookup, token creation or sending, including unknown/archived identities. A storage failure rejects the operation with generic temporary-unavailability guidance.

Only normalized email/IP SHA-256 identifiers, counters and Unix timestamps are stored in this table; no passwords, codes or tokens. Expired rows are removed in bounded batches during throttle requests. IP identification uses canonical `REMOTE_ADDR`, never untrusted forwarding headers. If the destination is behind a reverse proxy, verify the web server's trusted-proxy/real-IP configuration supplies the genuine client address in `REMOTE_ADDR`; do not enable arbitrary client-controlled `X-Forwarded-For` processing in PHP. All email-request feedback and public sign-in method choices are independent of account existence/enrolment. Account lookup no longer advertises whether an email is registered. Existing signups, remember-me and one-time-token consumption retain their established policies.

Regression command:

```bash
php scripts/test_auth_throttle.php
```

This test creates a uniquely named `auth_throttle_test_<random>` fixture table so independent connections/workers share real persistent state, plus connection-local temporary user/role tables. The fixture table is dropped in `finally`; it sends no emails and changes no real users or tokens. Tests cover failed fifth and successful fifth attempts, fixed cooldown and exact expiry, successful password/TOTP login, mixed login methods, normalized emails, different sessions/connections/IPs, separate reset/magic budgets, anonymous feedback, spoofed forwarding headers, storage failure and ten parallel workers. If the test process is forcibly killed, remove only its identified `auth_throttle_test_*` fixture table after confirming no test is still running; never truncate the production limiter as routine cleanup.

After deployment, verify the sign-in chooser offers the same methods for known/unknown emails, a disposable account signs in correctly using password and authenticator, and repeat anonymous email requests show temporary retry guidance. Do not use a real administrator account to generate deliberate failures. Recovery is automatic after cooldown; an environment rollback may leave the additive table in place. Reference: [OWASP Authentication Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Authentication_Cheat_Sheet.html).
