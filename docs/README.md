# Project documentation

Read this index and [WORKFLOW.md](WORKFLOW.md) at the start of each session, as directed by the root `AGENTS.md`.

Current session handover: [private security review notes](../../private/handover/2026-11-12-security-review.md) (local file outside the repository and public web root).

- [WORKFLOW.md](WORKFLOW.md): server, workspace, permissions and development setup standards.
- [DEPLOYMENT.md](DEPLOYMENT.md): required deployment and migration checks; read before deploying.
- [Onboarding live rollout](ONBOARDING_LIVE_ROLLOUT.md): populate via ildra.witecanvas.com, change DNS, then explicitly enable final-domain invitations.
- [Live setup checklist](LIVE_SETUP_CHECKLIST.md): manual setup, database/content transfer and rollout limits for the recent onboarding and membership work.
- [MASTER_RIDE_PRICING.md](MASTER_RIDE_PRICING.md): master ride classes, scheme selections, event price snapshots and migration.
- Operational scripts: see [CLI-only access and HTTP denial](DEPLOYMENT.md#cli-only-operationaltest-scripts-security-priority-3); `scripts/` is not a public endpoint.
- Authenticator changes: see [fresh authentication and setup verification](DEPLOYMENT.md#fresh-authentication-for-authenticator-changes-security-priority-2); implementation is in `auth_app_security.php`.
- Password/session revocation: see [security priority 1 and rollout behaviour](DEPLOYMENT.md#password-replacement-revokes-sessions-and-remembered-logins-security-priority-1); implementation is in `auth_sessions.php`.
- Authentication email links: see the [approved origins and launch behaviour](DEPLOYMENT.md#approved-password-reset-and-magic-login-email-origins); the shared allowlist is in `auth.php`.
- Authentication throttling: see the [deployment instructions](DEPLOYMENT.md#persistent-authentication-and-email-request-throttling-12-november-2026); thresholds are in `auth_throttle.php`.
- [EMAIL_DELIVERY.md](EMAIL_DELIVERY.md): Truska and Amazon SES settings, routing by email type, private credentials and verification.
- [Email delivery status](EMAIL_DELIVERY_STATUS.md): accepted/delivered/bounce reporting, signed SES feedback and the separate AWS/SNS setup.
- [Onboarding wording editor](ONBOARDING_WORDING.md): tabbed admin HTML messages for the account setup screens.
- [Amazon SES enhancements](amazon-enhancements.md): deferred email improvements and future requests, including direct website links instead of Amazon tracking redirects.
- [DEPENDENCY_REVIEW.md](DEPENDENCY_REVIEW.md): external services, dependencies and review checklist; read before changing integrations or dependencies.
- [IMPORT_WORKSPACE.md](IMPORT_WORKSPACE.md): development-only repeatable reset, protected Developer login and users/people/horse CSV trials.
- [OLD_IMAGE_LIBRARY.md](OLD_IMAGE_LIBRARY.md): repeatable FTP/VS Code image processing, Events/Rider Notes/Venues selection and retirement.
- [SOURCE_DATA.md](SOURCE_DATA.md): where to upload source data and how to prepare future imports.
- [Account migration pilot](ACCOUNT_MIGRATION_PILOT.md): development-only selected/unsent invitations, user/person onboarding, per-horse logbook choices, Shares placeholder, closure and internal notes.
- [Account migration and first login plan](plans/ACCOUNT_MIGRATION_AND_FIRST_LOGIN_PLAN.md): proposed exports, sharing, activation and guided setup.
- [SESSION_CHANGELOG_2026-08-05.md](SESSION_CHANGELOG_2026-08-05.md): historical implementation notes.
- [plans/](plans/): implementation plans for admin permissions, audit logging, basket holds and late entries, and dynamic event entry forms. Read the matching plan before work in these areas.

Paths and commands in these documents are relative to the project web root unless explicitly stated otherwise.
