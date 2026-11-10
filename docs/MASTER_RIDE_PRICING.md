# Master ride classes and pricing

Ride classes and prices are managed under **Pricing Schemes → Master Ride Classes & Prices** (`admin/ride_classes.php`). Each record holds a name, code, category, optional distance, price, optional recognised non-ILDRA-member price, membership restriction, rider eligibility, accompanying-adult requirement, display order, active flag and public-pricing visibility flag. The existing legacy junior flag is retained as the accompanying-adult requirement; it is not the rider-age restriction. Age is controlled by Rider Eligibility. The supplied live junior rows retain their original flag values.

Ride pricing schemes select master classes and their order. Names, prices and eligibility cannot be edited independently in a scheme. Creating a special price means adding a separate master class, optionally hiding it from the public price list, then selecting it in a scheme. An inactive master class is unavailable for new event templates and public pricing. Existing events keep their snapshots. Inactive scheme selections are retained for later reactivation, even when a scheme is renamed or edited. Non-ride schemes retain their independent editable rows.

The public **Ride prices (live data)** content element reads the active, publicly visible master list once. It does not render every scheme, so classes are not repeated or assigned conflicting prices. Rider eligibility displays All, Junior or Senior from the actual eligibility field.

## Event snapshots and locks

Applying a scheme creates independent event pricing rows. Master or scheme edits never automatically update existing events, including drafts. The event editor permits enabling/removing existing classes before opening, but ride names, prices and eligibility are read-only; use **Apply Template**, then **Save**, to obtain current master rates. Server-side validation resolves applied templates against current master records, and preserves historical snapshot fields on ordinary edits rather than trusting posted prices. A members-only class selection is valid; an event does not need a non-member price row. Price replacement checks the event lock inside its database transaction, and a mismatched editor/posted event ID is rejected.

Pricing locks when the first member or non-member opening time is reached on a published event, or when the event already has a booking item. The usual one-month-before-event fallback applies when the member opening time is absent. The lock is stored separately and survives unpublishing or rescheduling. Locked event pricing rows, enabled classes, event type and late-entry fee cannot be changed through the editor. Ordinary edits preserve price row IDs and legacy class snapshots. Locking applies to non-ride events too.

Save rate changes before publishing an event whose opening time has already passed. The editor rejects combining changed rates with immediate opening on an existing event; save as a draft (or with a future opening time), then publish. This avoids publishing one set of rates and attempting to replace it after entries have opened.

Copying a ride into a new draft resolves its classes to current master rates and eligibility. If a historic class cannot be matched uniquely, copying stops with an explanation instead of silently carrying forward an obsolete rate; create the new event from a current scheme. Copying does not alter the source event. Non-ride copying retains its existing behaviour.

## Database structure and migration

The additive schema is in `migrations/2026-11-10-master-ride-classes.sql`:

- `ride_classes`: master definitions, with unique codes and initial-source keys.
- `ride_scheme_classes`: scheme selections and per-scheme order.
- `event_pricing_locks`: permanent event pricing locks.
- `ride_pricing_state`: completed migration fingerprint and stable Ride type IDs.

The PHP migration previews the current destination database and maps every ride scheme. It uses the chosen all-classes scheme as the master, exact class codes for matching, and the unique PR membership/age combination for older PR-only rows with no codes. It rejects duplicate codes, ambiguous mappings, empty ride schemes and mixed ride/non-ride schemes. It reports price conflicts. The live PR-only Junior Member row changes from £13 to the authoritative master £15; historical event prices are unaffected.

Approved normalisations are CRT → CTR for the 64km names/codes, and the missing closing bracket in the Platinum display name. Other truncated legacy codes remain as supplied. The authoritative 17-row dataset has real prices for Platinum, Diamond and all ER rows; VPR eligibility is All. No pending-price status is introduced.

The migration retains the original `pricing_scheme_rows` as an audit/reference snapshot. Ride scheme reads use the master mappings after activation; independent non-ride reads still use their original rows. Repeated migration calls preserve subsequent administrator changes to the master and schemes. See [DEPLOYMENT.md](DEPLOYMENT.md) for the required backup, preview and apply procedure.

Development's four CSV exports are stored privately under `../private/source-data/2026-11-10-live-pricing/`. Development pricing configuration was refreshed from those exports after a full private database backup. Extra development-only event types were retained to preserve existing event references. No production events, users, entries or payments were imported. Checksums of events, event pricing rows, bookings, booking items and finance transactions matched before and after refresh/migration.

## Verification

```bash
php scripts/test_master_ride_pricing.php --render-fixture
node scripts/tests/master_ride_pricing.cjs
```

Database tests use synthetic data in connection-local temporary tables that shadow application tables. No persistent application data, emails or Stripe operations are involved. The browser test uses the generated synthetic scheme form and actual draft/locked event-pricing view fragments. Also verify the signed-in master editor and event editor in the destination environment, especially a pre-opening reapply and an opened event's read-only rates.
