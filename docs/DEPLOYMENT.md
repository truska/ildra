# Deployment checklist

## Monthly Memberships & Logbooks collections

Finance now has a **Memberships & Logbooks** tab. Months follow the original `payment_stripe` transaction timestamp converted to Europe/London, never the membership/logbook product year. Membership and logbook receipts share one monthly balance, with separate subtotals. Booking items allocated to events remain in the Events report. Mixed-basket payments and Stripe fees are allocated proportionally in pennies. Internal checkout debits, simulated payments and credit-only purchases do not create collectible Stripe funds.

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
