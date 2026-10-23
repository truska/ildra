# Server Workflow Standard

This file is the master setup standard for new sites and workspaces on this server.

The goal is simple:

- every site should open the same way
- every workspace should have the same structure
- Codex and terminal tools should work without repeated sandbox workarounds
- dev and live paths should be easy to understand

## 1. Preferred Directory Layout

For each site, keep the same shape:

- `.../web` for the site code
- `.../private` for private config and secrets
- `.../log` for logs

If a VS Code workspace file is used, it should include:

- `web`
- `../private`
- `../log`

Example:

```json
{
  "folders": [
    { "name": "web", "path": "." },
    { "name": "private", "path": "../private" },
    { "name": "log", "path": "../log" }
  ]
}
```

## 2. Git Standard

Every dev site must have a normal readable `.git` directory inside the site root.

Required:

- `.git` must exist in `web/.git`
- it should be a normal Git directory or a valid worktree pointer
- the Codex workspace path must be the real dev checkout, not an awkward indirection

Avoid:

- hidden symlink chains
- mixed live/dev worktrees with unusual ownership
- setups where `.git` is readable only outside the normal dev path

## 3. Permissions Standard

The most important consistency rule:

- the site root must be readable enough for dev tools to inspect `.git`

Recommended:

- site root directory: `750`
- `.git` directory: `755`
- normal files: `644`
- executable scripts only where needed: `755`

In practice:

- owner should be the site user
- group should be the shared site group
- Codex/sandbox-safe tooling must be able to traverse and read the repo metadata

Avoid using a site root permission like `710` when the workspace is expected to be used by tooling. That is what causes the repeated `.git` sandbox failures.

## 4. Ownership Standard

Use one clear ownership model per site.

Preferred:

- site files owned by the site user and site group
- avoid mixing `root`, `webX`, and other owners inside the working tree unless there is a specific reason

If ownership is mixed, tools behave unpredictably and setup drifts over time.

## 5. Config Standard

Each site should have:

- `config.php`
- `config.example.php`
- private overrides in `../private` where needed

Secrets should not be hardcoded in multiple places unless there is a deliberate operational reason.

## 6. Workspace Standard

Each site should include one workspace file named after the dev domain, for example:

- `dev-example.witecanvas.com.code-workspace`

It should:

- open `web`, `private`, and `log`
- set a clear window title
- optionally set colour customisations for environment clarity

## 7. New Site Checklist

When setting up a new site:

1. Create the standard folder structure:
   `web`, `private`, `log`
2. Create or clone a proper Git working copy into `web`
3. Set ownership consistently
4. Set directory permissions so the workspace root is readable by dev tooling
5. Add the `.code-workspace` file
6. Add `config.example.php`
7. Confirm these commands work without escalation:
   `pwd`
   `ls`
   `rg --files`
   `git status`
   `php -l somefile.php`
8. Only after that, start coding work

## 8. Existing Site Repair Checklist

If a site behaves differently from the others, check these first:

- is `web/.git` present and readable?
- is `web` permission too restrictive?
- is ownership mixed across the repo?
- is the Codex workspace pointing at the real dev copy?
- does `pwd` fail inside sandbox before code is even touched?

If yes, fix the environment first.

## 9. Rule Going Forward

No new site should be considered "ready" until the basic read-only tool checks pass inside the normal sandboxed workspace.

That is the acceptance test for a correct setup.
