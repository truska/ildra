# Dynamic Event Entry Forms

## Purpose

Extend the current ride-oriented event entry form into a reusable, data-driven
form system for rides, dinners, training courses, First Aid events, and future
event types, without making routine event setup difficult for administrators.

## Current behaviour and constraints

- `event_types` currently provides broad categories such as Ride, Awards and
  Training. There is no Event Type management screen yet.
- A pricing scheme is a reusable template of pricing rows. It is assigned to
  one or more Event Types, and one scheme can be the default for each type.
- New events receive a *copy* of their Event Type's default pricing rows. An
  event can now also apply any compatible pricing template from its editor.
  This is intentionally a copy, not a live link to the template.
- Entry Components are reusable form items that can be enabled for an event.
  They can be limited to particular Event Types.
- The public entry page is still fundamentally ride-shaped: rider, horse and
  class selection are currently built into the page outside the configurable
  component list. Removing a Horse-related component does **not** remove the
  mandatory horse selection.
- There is an existing event-copy flow. This should remain the fastest way to
  create repeat events once an event has been configured correctly.

## Choice groups implemented in this development work

Entry Components now support two input kinds:

- `choice_single`: choose one option (radio buttons)
- `choice_multiple`: choose one or more options (checkboxes)

Each reusable option has:

- label
- optional note, such as allergens or other menu detail
- optional price adjustment
- display order

A zero price adjustment is deliberately not displayed to entrants. Non-zero
adjustments are included in the visible form total and are stored with the
booking as a snapshot of the selected option label, note and price.

The schema is created by `ensureEntryComponentsTables()` and is also recorded
in `migrations/2026-09-10-entry-component-options.sql`.

Example dinner setup:

- Component: Starter (`choice_single`, required)
- Component: Main course (`choice_single`, required)
- Component: Dessert (`choice_single`, required)

These components may be reused on events where the same menu applies. A new
component is currently the way to use a materially different menu.

## Agreed design direction

Do not build a separate hard-coded form for every Event Type. Instead, use a
shared entry-form engine with an Event-Type **form profile** and reusable
blocks.

### Scope of form blocks

| Scope | Examples |
| --- | --- |
| Booking-level | booking contact, parking, table request |
| Per-attendee | ticket type, lunch, starter, main, dessert |
| Ride-specific | rider, horse, ride class |

The crucial new reusable block is an **Attendee list**. It lets a single
booking/basket item contain two or more attendees, each with separate choices.
This is required for a dinner booking for a partner or a course booking for
multiple people. A quantity input alone is insufficient because it cannot
capture separate menu selections.

Pricing should be composed as:

`base attendee/event price + required add-ons + selected option adjustments`

Ride pricing rows remain appropriate for a ride's mutually exclusive class.
They should not be used to model independent menu choices. Choice groups are
the appropriate mechanism for that.

## Proposed layers

1. **Event Type**: broad behaviour and default entry-form profile.
   Examples: Ride, Dinner, Training, First Aid.
2. **Event Template**: reusable setup within an Event Type.
   Examples: Annual Dinner, First Aid with Lunch, Standard Ride, Winter Ride.
3. **Event**: the dated occurrence. It inherits a copied setup from its
   selected template, then allows event-specific changes.

The normal New Event workflow should remain short:

1. Choose Event Type.
2. Choose a compatible Event Template, or start from the type default.
3. Enter title, date and venue.
4. Save.

Advanced configuration (pricing rows, form layout, components and attendee
rules) should be collapsed behind an explicit “Customise event setup” action.

## Suggested profiles

### Ride

- rider selection/details
- horse selection/details
- ride class/pricing row
- booking contact
- ride-specific components

### Dinner

- booking contact
- repeatable attendee list
- per-attendee ticket type/base price
- per-attendee Starter, Main and Dessert choice groups
- optional booking-level extras

### Training / First Aid

- booking contact
- one or more named attendees
- per-attendee attendance fee/ticket type
- optional flat-fee lunch or per-attendee lunch choice group

## Implementation sequence when work resumes

1. Add an Event Type admin screen and persist an entry-form profile/default.
2. Move existing hard-coded ride fields into reusable profile blocks, while
   preserving Ride behaviour exactly.
3. Add the attendee-list block and booking metadata structure for attendee
   records and per-attendee price snapshots.
4. Build the Dinner profile and validate the whole booking/basket/confirmation
   path.
5. Add Event Templates that copy profile, form layout, components and pricing
   setup into a new event.
6. Keep and improve event copying as the quick path for recurring events.

## Important validation and data rules

- Validate required fields and selection limits server-side; browser controls
  are only a convenience.
- Store labels, notes and prices selected at booking time so historical
  bookings remain accurate after a menu/template changes.
- Keep template changes from modifying already-created events or bookings.
- Distinguish booking-level and attendee-level components explicitly.
- Do not expose horse/rider/class blocks for a non-ride profile.

