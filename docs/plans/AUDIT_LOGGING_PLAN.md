# Audit Logging Plan

Status: planning notes only; no implementation or database changes yet.

## Purpose

Introduce a searchable audit trail for authentication, security events, and administrative changes. The log should answer:

- Who performed an action?
- What action was attempted or completed?
- Which record was affected?
- When and from where did it happen?
- Did it succeed or fail?
- For changes, what materially changed?

## Events to record

### Authentication and account security

Record successful and failed events for:

- Password login
- Magic-link login
- Authenticator-app login
- Remember-me/session restoration
- Logout
- Account creation
- Password creation, reset, or change
- Magic-link request and invalid/expired link use
- Authenticator setup, confirmation, disablement, and failed code attempts
- Admin impersonation start and end
- Permission-denied attempts to access protected admin functions

For failed login attempts, record the submitted email only after normalising it. Never record passwords, authentication codes, magic-link tokens, session IDs, or remember-me tokens.

### Pages and content

Record create, update, publish/unpublish, archive, and delete operations for:

- Pages and page content sections
- Page images and documents
- Hero and welcome content
- Advertising
- FAQs and Help Centre content
- Footer/menu placement settings
- Public and admin menus
- Site/global settings

### Administrative operations

Record all material admin create, update, archive, restore, delete, duplicate, publish, and permission operations, including:

- Events, venues, event types, entry components, and pricing schemes
- Users, roles, levels, account status, and impersonation
- People and horses when changed by an administrator
- Membership and horse-logbook product definitions and prices
- Email configuration and administrative email actions
- Finance adjustments and administrative booking changes
- Document and image library records

## Events not duplicated in the audit log

Successful business transactions already have their own durable records and should not be copied in full into the general audit log:

- Event entries and normal entry workflow activity
- Successful membership registrations/purchases
- Successful horse-logbook registrations/purchases
- Normal booking and payment records already represented in the booking/finance system

The audit log may store a lightweight reference when an administrator changes one of these records. It should not duplicate the full transaction data.

Failures that are not visible in the existing membership, horse-logbook, booking, or payment screens should be logged. Examples include rejected validation, failed purchase creation, payment callback failure, and an exception occurring after payment but before the local record is completed.

## Data to retain for each event

- UTC timestamp
- Event category and action, using stable machine-readable names
- Outcome: success, failure, or denied
- Actor user ID, email/username, role, and level when known
- Effective user ID when an administrator is impersonating another user
- Target entity type and record ID
- Human-readable summary
- Changed fields with safe before/after values where appropriate
- IP address
- Raw user-agent string
- Parsed browser, operating system, and broad device type where practical
- Request method and route, excluding sensitive query values
- Request/correlation ID to connect related events
- Error or reason code for failures, with a safe message

Store both immutable user IDs and a snapshot of the actor email/name. The snapshot preserves useful history if the user is later renamed or deleted.

## Security and privacy requirements

- Treat IP addresses and user-agent information as personal data.
- Never log passwords, card details, authentication codes, tokens, cookies, session IDs, secret keys, or complete request bodies.
- Redact sensitive fields before serialising before/after changes.
- Make audit records append-only from the application. Corrections should create another audit event rather than editing history.
- Restrict audit viewing to superadmins, with an optional read-only auditor permission later.
- Audit access to the audit-log screen itself and any export.
- Use UTC in storage and display dates in the administrator's configured timezone.
- Add retention rules rather than retaining all data indefinitely. A suggested starting point is 12 months for routine events and longer for security/admin change events, subject to the organisation's GDPR policy.
- Consider hashing or chaining records, or exporting them to protected storage, if tamper evidence becomes necessary.

## Suggested database shape

Use one append-only `audit_log` table with indexed columns for timestamp, category, action, outcome, actor, target type/ID, and IP. Store structured change details and safe metadata in JSON columns where supported, with text fallback for compatibility.

Do not create separate tables for every event type. Stable event names and structured metadata will make one searchable log easier to operate.

## Suggested administration screen

Add Admin → Audit Log with:

- Date/time range
- Actor/user search
- Category and action selectors
- Success/failure selector
- Target type and ID search
- IP search
- Browser/device search
- Expandable event details and changed fields
- CSV export restricted to authorised users
- No edit or delete controls

## Implementation approach

1. Add the append-only table and a small central logging service.
2. Instrument successful and failed authentication, logout, and impersonation first.
3. Add a reusable admin-change wrapper that records safe before/after values.
4. Instrument pages/content, settings, menus, pricing, events, users, and other admin operations.
5. Add operational failure events for membership, horse-logbook, booking, and payment flows where failures are not otherwise visible.
6. Build the read-only Audit Log administration screen.
7. Add retention/cleanup processing and document GDPR responsibilities.
8. Test that secrets and sensitive form values can never enter the log.

## Further recommendations

- Define event names before implementation, for example `auth.login.success`, `auth.login.failure`, `admin.page.updated`, and `admin.user.role_changed`.
- Distinguish the actor from the effective/impersonated user on every relevant event.
- Log attempted destructive actions as well as completed ones when they fail or are denied.
- Prefer field-level change summaries over saving full database rows.
- Add a request ID early; it will make troubleshooting multi-step actions substantially easier.
- Keep technical application errors in server error logs, while the audit log stores a safe operational summary and correlation ID.
