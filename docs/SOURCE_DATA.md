# Source data for future database imports

Upload source files to:

```text
/var/www/dev-ildra.witecanvas.com/private/source-data/
```

This is `../private/source-data/` from the web project, also available through the workspace's private folder. It is outside the public web root and the application Git repository. Use your file manager or SFTP; this is a storage folder, not a website upload form.

Keep each delivery in its own folder, for example `2026-10-23-members-original-system/`. Include the original CSV, spreadsheet, JSON or SQL files and a short note with the source system, export date, meaning of fields, and intended database use. Keep original files unchanged; put any cleaned copies and mapping notes alongside them with clear names.

Files are held for later review. Nothing watches this folder or automatically imports its contents. Before an import, inspect the data, agree field mappings and duplicate handling, back up the target database, and validate a preview of proposed changes. Use the development-first source workflow in `AGENTS.md` for reusable import scripts and migrations.

The folder currently uses mode `2770` with inherited access from the private directory. Preserve private access when uploading; do not move source data into public media folders or commit it to the application repository.

## Legacy account opening balances

`ildra-credits.csv` is handled by **Legacy Opening Balances** in the admin menu. Review its full preview and back up the target database before importing it into the pending register. Negative source balances become positive GBP credit; positive source balances become debit. The supplied sample has 41 records (£803 credit and £51 debit). Importing the register does not itself allocate money to accounts. Matching accounts confirm ownership of the original email at login or before checkout; administrators can allocate unmatched records manually after checking ownership.

The PHP worker has read-only access to this specific file and traversal of this folder, without directory listing or inherited access to other uploads. Additional private files require a separate access decision. Keep this original CSV unchanged and use synthetic fixtures or a separately named copy for testing. See [DEPLOYMENT.md](DEPLOYMENT.md) for schema setup, import review, allocation and testing details.
