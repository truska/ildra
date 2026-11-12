# Project documentation

Read this index and [WORKFLOW.md](WORKFLOW.md) at the start of each session, as directed by the root `AGENTS.md`.

- [WORKFLOW.md](WORKFLOW.md): server, workspace, permissions and development setup standards.
- [DEPLOYMENT.md](DEPLOYMENT.md): required deployment and migration checks; read before deploying.
- [MASTER_RIDE_PRICING.md](MASTER_RIDE_PRICING.md): master ride classes, scheme selections, event price snapshots and migration.
- Operational scripts: see [CLI-only access and HTTP denial](DEPLOYMENT.md#cli-only-operationaltest-scripts-security-priority-3); `scripts/` is not a public endpoint.
- Authenticator changes: see [fresh authentication and setup verification](DEPLOYMENT.md#fresh-authentication-for-authenticator-changes-security-priority-2); implementation is in `auth_app_security.php`.
- Password/session revocation: see [security priority 1 and rollout behaviour](DEPLOYMENT.md#password-replacement-revokes-sessions-and-remembered-logins-security-priority-1); implementation is in `auth_sessions.php`.
- Authentication email links: see the [approved origins and launch behaviour](DEPLOYMENT.md#approved-password-reset-and-magic-login-email-origins); the shared allowlist is in `auth.php`.
- Authentication throttling: see the [deployment instructions](DEPLOYMENT.md#persistent-authentication-and-email-request-throttling-12-november-2026); thresholds are in `auth_throttle.php`.
- [DEPENDENCY_REVIEW.md](DEPENDENCY_REVIEW.md): external services, dependencies and review checklist; read before changing integrations or dependencies.
- [SOURCE_DATA.md](SOURCE_DATA.md): where to upload source data and how to prepare future imports.
- [SESSION_CHANGELOG_2026-08-05.md](SESSION_CHANGELOG_2026-08-05.md): historical implementation notes.
- [plans/](plans/): implementation plans for admin permissions, audit logging, basket holds and late entries, and dynamic event entry forms. Read the matching plan before work in these areas.

Paths and commands in these documents are relative to the project web root unless explicitly stated otherwise.
