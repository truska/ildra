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
