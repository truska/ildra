# Deployment checklist

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
