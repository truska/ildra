# Deployment checklist

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
