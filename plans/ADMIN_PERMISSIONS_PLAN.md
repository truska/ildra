# Admin permissions plan

## Status

- The `manager` role has been added to application code at level 4, below
  `admin` (5) and `superadmin` (6).
- Until this plan is implemented, Manager deliberately has the same access as
  Admin. This keeps the initial role rollout simple.
- The Manager database role is supplied by
  `migrations/2026-08-21-manager-role.sql`.
- Do not run that migration or change live users/menu permissions as part of
  this planning work.

## Roles

| Role | Purpose |
| --- | --- |
| User | Normal website account holder. |
| Organiser | Works with assigned ride/event operations and entries. |
| Manager | Manages approved website content and day-to-day CMS records. |
| Admin | Manages sensitive operational areas, users and business settings. |
| Superadmin | Full technical and security control. |

## Target section groups

### Content — Manager and above

- Pages, page content sections, banners and page images
- News and ride reports, including sections and images
- Advertising
- FAQs, awards and winners
- Help and Account Help
- Document and Image Library

### Events — Manager and above

- Events and venues
- Entry components and pricing schemes
- Event entries and ride notes

Organisers should receive only the event/entry capabilities they need, rather
than all Manager content permissions.

### Operations — confirm during the permissions pass

- Bookings
- Memberships and members
- People and horses
- Dev Tasks

These can initially remain Manager-and-above, then be tightened if required.

### Sensitive administration — Admin and above

- Finance and event finance
- User accounts and role assignment
- Site-wide settings
- Administration-menu structure
- Live email sending and email-log access

### Technical administration — Superadmin only

This is the protected group for technical settings and services. Start with:

- Live email provider/settings, sender configuration and delivery controls
- Authentication/security settings and impersonation controls
- Database, migration and deployment tools
- System integrations, API keys, webhooks and scheduled jobs
- File-storage configuration and system image-folder controls
- Permission/capability configuration

Additional technical settings should be placed here by default unless there is
a clear operational reason for Admin access.

## Implementation design

Menu visibility is not a security control. Each administration route must also
enforce the same permission as the menu item.

Replace scattered role checks with named capabilities, for example:

```text
manage_pages
manage_news
manage_advertising
manage_events
manage_entries
manage_memberships
manage_people_and_horses
manage_finance
manage_users
manage_live_email
manage_site_settings
manage_permissions
manage_technical_settings
```

The admin menu should store the required capability for each item. The page
that handles that item must call the same capability check before displaying or
changing data.

Roles then receive capabilities through one central map or database table.
This supports the specialist Organiser role without treating it as merely a
lower numeric level.

## Safety rules for the future permission editor

- Only Superadmin can change the capability matrix or technical permissions.
- Admin may assign users only to roles at or below Admin.
- Manager must not be able to grant themselves access to Finance, Users,
  Settings, live email settings or permission configuration.
- A menu item must never appear if its underlying page would deny access.
- Direct URLs must remain protected even if a user knows the route.

## Delivery order

1. Confirm the final section groups and role boundaries in this document.
2. Add central capability helper functions and tests.
3. Apply capabilities to the Admin menu and every admin route.
4. Move Finance, Users, Settings and email controls to Admin-only.
5. Move technical settings, including live email configuration, to
   Superadmin-only.
6. Add a read-only permissions overview for Admins and a Superadmin-only
   capability editor if required.
7. Test each role with direct URLs as well as visible menu items.

## Front-end editor shortcuts (separate work)

For authorised content managers, public pages can later show small icon-only
shortcuts that open in a new tab:

- Manage page content: filtered Content Sections list for the current page.
- Edit content: the individual visible content section.
- Edit page: the page's title, introduction and base settings.

These buttons must use the same `manage_pages` / content capability checks as
their linked admin routes.
