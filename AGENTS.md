# Project development notes

- After creating a new public web file, verify that the web server can read it. Public PHP pages and other served assets should normally use mode `664`, matching the existing files in this project; run `chmod 664 <file>` and confirm with `ls -l`.
- When adding a new image-upload section, pre-create its `filestore/images/<section>/<size>` directories and verify that the PHP/web user can write to them. This deployment's established upload folders use group/setgid or shared-write permissions; test the actual upload path rather than assuming `mkdir()` can create it at runtime.
- Before deploying Ride Report galleries to another environment, Codex must follow `DEPLOYMENT.md`. Do not mark the deployment complete until the live `news` image directories exist, have shared-write permissions, and pass the documented writability checks. Empty Git-tracked directories alone do not guarantee correct live permissions.
