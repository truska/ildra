# Pre-launch Test Data Reset

`admin/testing_reset.php` supports the agreed pre-launch cleanup at
`ildra.witecanvas.com` using its **own private opt-in**. It is disabled there
unless explicitly enabled. This does not enable Import workspace, Onboarding
reset modes or per-user development restart tools. Their development guard is
unchanged. Existing opted-in development Test Data Reset access is retained.

## Access and safety

The pre-launch opt-in must contain literal values matching all of:

- `enabled` is the PHP boolean `true`.
- `web_root` equals `realpath()` of the installed web directory.
- `database` equals `SELECT DATABASE()` on the application's active connection.
- Both configured and requested hostnames are `ildra.witecanvas.com` (Host
  comparison is case-insensitive). Ports, trailing dots, alternate/final domains,
  missing hosts and lookalike domains are rejected.
- The signed-in user is Superadmin or Developer; impersonation remains blocked
  by the shared admin bootstrap. POSTs retain the shared CSRF check.

There is no database setting or web UI toggle to enable this facility. Copying
source, setting a Host header or enabling account migration does not enable it.
A `../private/prelaunch-testing-reset.disabled` marker overrides the new opt-in.
Missing, unreadable or malformed opt-in files fail closed. The Tech reset card
uses the same environment gate as the endpoint; disabled requests return 404
once the normal admin authentication checks have run.

The existing reset runner and deletion allowlist are unchanged. It retains
logins and each selected person, optionally the calendar, and removes the
operational data listed in its preview. It does not refund/delete Stripe-side
records or stop external workers. Preview tokens expire after 15 minutes and
are now also bound to the current actor and root/database/host fingerprint.
Plans and counts are checked before running and again under database locks.
The exact phrase `RESET TEST DATA` and quiet-site acknowledgement remain required.
The private opt-in is checked again immediately before invoking the runner.
A full schema-and-row JSONL database backup must be written and flushed to
`../private/testing-reset-backups/` before any deletion. Backup failure cancels
reset; transactional failures roll back. Backups have mode 600.

## Deploy to ildra.witecanvas.com — source first, private opt-in second

These are operator instructions; implementation/testing did **not** execute
these commands against the destination, create its opt-in or reset any data.

1. Follow DEPLOYMENT.md: back up the destination database, private configuration
   and uploaded files, then deploy the pushed development commit from the
   destination checkout using `git pull --ff-only`. Do not edit reusable source
   on staging and do not copy development's database or `import-workspace.php`.
   No database migration is required for this change.
2. From the destination web directory, as the site's PHP/CLI user, run this
   one-time generator. It reads the actual destination root and connected
   database name and writes **literal, disabled pins** to the exact private
   file. It never prints credentials or enables/reset/imports anything. It
   refuses to overwrite an existing opt-in.

   ```bash
   php <<'PHP'
   <?php
   require 'db.php';
   $alerts = [];
   $pdo = createPdo(require 'config.php', $alerts);
   if (!$pdo) throw new RuntimeException('Database unavailable; no opt-in written.');
   $root = realpath('.');
   $database = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
   if (!$root || $database === '') throw new RuntimeException('Missing destination pins.');
   $config = [
       'enabled' => false,
       'web_root' => $root,
       'database' => $database,
       'host' => 'ildra.witecanvas.com',
   ];
   $path = dirname($root) . '/private/prelaunch-testing-reset.php';
   $file = fopen($path, 'x');
   if (!$file) throw new RuntimeException('Opt-in already exists or private directory is not writable.');
   chmod($path, 0640);
   $text = "<?php\nreturn " . var_export($config, true) . ";\n";
   if (fwrite($file, $text) !== strlen($text) || !fflush($file)) {
       fclose($file);
       unlink($path);
       throw new RuntimeException('Opt-in write failed.');
   }
   fclose($file);
   echo "Disabled opt-in written to $path\nRoot: $root\nDatabase: $database\n";
   PHP
   ```

   The destination is not mounted in the development workspace, so its physical
   path and database name are deliberately obtained there, rather than guessed.
   `config.prelaunch-testing-reset.example.php` documents the same private array
   if manual installation is preferred. Do not use runtime `realpath()` or read
   the current database dynamically **inside the active private array**: those
   would cease to be fixed destination pins.
3. Review the generated root/database against the intended staging installation.
   Ensure the private file is owned/readable by its PHP/site user (640 normally).
   Pre-create the backup directory as that same user:

   ```bash
   mkdir -p ../private/testing-reset-backups
   chmod 700 ../private/testing-reset-backups
   php -r '$p="../private/testing-reset-backups/.write-check-".bin2hex(random_bytes(8)); $f=fopen($p,"x"); if(!$f)exit(1); fclose($f); unlink($p); echo "Backup directory writable\n";'
   ```

   Run the writability check as the actual PHP worker account if the CLI account
   differs; a CLI user's successful write alone does not prove PHP access.
   Existing shared ownership/ACLs may be retained instead of changing mode if
   needed. Verify public source files `testing_reset_access.php`,
   `admin/testing_reset.php` and `admin/tech.php` are readable by PHP.
4. Only while this is still a pre-launch test database, edit the private array's
   `enabled` value from `false` to `true`. Confirm no retirement marker exists;
   do not remove a marker left by the launch shutdown process. Reload PHP-FPM
   if its opcode-cache policy does not pick up private-file edits.
5. Sign in as Superadmin/Developer at exactly `https://ildra.witecanvas.com`.
   Tech should show Test Data Reset and the page should open. Preview only to
   verify retention choices/counts; **do not submit the reset action as a
   deployment test**. Ordinary Admin/Manager must not gain access. Check that
   Import workspace/Onboarding development resets remain unavailable.

## Permanently retire before real memberships or payments

Before accepting **any real memberships or payments**, leave a private marker
and remove the opt-in, as the destination site user:

```bash
touch ../private/prelaunch-testing-reset.disabled
chmod 640 ../private/prelaunch-testing-reset.disabled
rm ../private/prelaunch-testing-reset.php
```

Keep the marker through future deployments and private-config restores. It
prevents a stale opt-in from re-enabling pre-launch reset. There is no UI action
to remove it. Never restore/re-enable the facility against customer data.
The final domain `enduranceridingireland.com` is independently rejected by this
pre-launch guard even if an old opt-in remains. Revisit Tech and verify the card
is absent; direct GET and valid-CSRF POST requests to `admin/testing_reset.php`
as a signed-in Superadmin/Developer must return 404. Do not use a reset request
with a real preview/confirmation for this check. Retain existing private backups
securely; do not put them in the public web root or repository.

## Development verification

```bash
php scripts/tests/prelaunch_testing_reset.php
php scripts/tests/testing_reset_backup_offline.php
```

Both run offline on development. They test the actual guard/controller/Tech
code with synthetic paths and PDO/controller doubles: exact/mismatched pins,
unknown/final/lookalike hosts, default denial, unchanged development access,
roles, card visibility, actor/environment-bound preview expiry/tokens, typed
confirmation, quiet acknowledgement, stale plan/count rejection, opt-in
revocation and retirement marker. The unchanged actual backup runner is tested
against PDO/apply doubles and disposable `/tmp` files for full private backup
before apply, backup failure, stale counts and nontransactional engines. These
tests connect to no database and perform no real reset or external operation.
