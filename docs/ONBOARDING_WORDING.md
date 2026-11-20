# Onboarding wording editor — 20 November 2026

Admin → Onboarding provides separate tabs for Password, User settings, Person
settings, Horses, Shares, Finish and Thank you. Each saves its introductory
HTML message independently using the shared TinyMCE editor. Empty content hides
the message. Existing copy is the fallback until saved; Thank you is shared by
the completed page and completion dialog. Horse introductory copy also appears
when there are no horses. Form labels, prices and operational messages remain
part of the forms.

The editor is available to Manager, Admin, Superadmin and Developer, with an
endpoint role check and shared CSRF protection. Content uses the existing
server HTML allowlist at save and display. Values use `onboarding_copy_<step>`
keys in the existing `site_settings` table; no schema migration is required.
The menu item is added under the Admin section where present, with subsequent
menu moves preserved. No account progress or invitation records are changed.

Verification: PHP syntax checks, default/blank/custom HTML sanitisation checks,
and the existing isolated `scripts/tests/account_migration.php` suite passed.

The new editor and helper are committed separately from the earlier migration
implementation. The migration view connections remain with that uncommitted
implementation; commit and deploy them together before relying on this editor
for the public setup screens in another environment.
