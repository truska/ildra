# Basket Holds and Late Entries

## Purpose

Record the proposed direction for expired event baskets, capacity release, resuming an entry, and a future late-entry fee system. This is a discussion document: the questions below need decisions before implementation.

## Current behaviour

- The configured basket timeout defaults to 15 minutes, with a minimum setting of 5 minutes.
- An expired saved basket is deleted from the `baskets` table. It is not retained with an expired status and cannot currently be selected for resumption.
- The basket page clears an expired basket from the PHP session, but checkout and payment completion do not independently enforce the same expiry.
- Event capacity is based on non-withdrawn completed `booking_items`. Another customer's saved basket is not included in that count.
- The event page adds the current visitor's own basket to the capacity display/check, but does not see holds in other sessions.
- There is no explicit late-entry status, late-entry fee, or late-entry approval workflow.

## Working direction

Do not delete timed-out event entry details. Retain them with a status that does not reserve a place.

A possible lifecycle is:

- `active`: within the basket timeout and reserving a place, if capacity holds are introduced.
- `checkout`: payment is underway and the place remains reserved for a defined period.
- `expired`: details are retained, but no place is reserved.
- `completed`: payment/checkout completed and the event entry is confirmed.
- `cancelled`: deliberately removed or abandoned.
- `payment_failed`: payment failed and no place is reserved.

An expired entry should be visible to its account holder with an action such as **Try again** or **Resume entry**. Resuming must not simply restore the old reservation. It should check the current event conditions and then create a fresh basket/hold if permitted.

The fresh checks should include:

- current available capacity;
- rider membership and entry-opening eligibility;
- class, rider and horse eligibility;
- whether normal entries have closed;
- whether late entries are allowed;
- the current class/add-on prices; and
- any applicable late-entry fee.

## Capacity and timing tolerance

The club is not presently concerned if a near-simultaneous timing edge results in an event going over capacity by one or two places for a few minutes. Therefore, strict database locking does not have to be the first implementation priority.

The system should still make the normal capacity check when an expired entry is resumed and again when checkout completes. If the event has filled during checkout, the desired outcome needs to be agreed. A later enhancement could make the final allocation atomic if real-world use shows that the small timing tolerance causes problems.

## Late-entry integration

Resuming an expired entry after the normal closing time should not bypass entry rules. It should pass through the future late-entry policy.

A likely sequence is:

1. Customer selects **Resume entry**.
2. System checks whether a place is currently available.
3. System checks whether the normal entry period is open.
4. If entries have closed, system checks whether late entries are enabled and still within the late-entry period.
5. System recalculates the entry using current prices and adds the late fee where applicable.
6. Customer sees the revised price and confirms before payment.

## Questions to answer

### What reserves capacity?

1. Should an item reserve a place as soon as it is added to the basket, or only after checkout starts?
2. Should every basket item reserve one place, including entries containing multiple products/add-ons?
3. Should membership and horse-logbook products be excluded from capacity holds? (Recommended: yes.)
4. Should an event organiser be able to see active and expired holds?

### Timeout and checkout

5. Does entering checkout extend the original basket timeout, start a separate payment timeout, or leave the original deadline unchanged?
6. How long should an external payment page be allowed to retain a place?
7. Should activity in the basket extend the timer, or should only adding/removing an entry reset it?
8. Should the customer receive a warning shortly before expiry?
9. How long should expired entry details be retained before permanent deletion or anonymisation?

### Resuming an expired entry

10. Where should expired entries appear: basket, account/bookings, or both?
11. Should the action be called **Resume entry**, **Try again**, or something else?
12. If the event is full, should the expired entry remain available for another attempt?
13. Should resumption offer a waiting-list option when the event is full?
14. If a class or price has changed, should the customer be shown a clear comparison before confirming?
15. If the selected rider, horse or shared access is no longer valid, should the form reopen with the remaining details prefilled?

### Capacity changes during payment

16. Given the accepted tolerance of one or two places, should a payment that completes just after capacity is reached still be accepted automatically?
17. If not, should it be held for administrator review, credited to the customer's account, or refunded automatically?
18. Should administrators be warned when confirmed entries exceed capacity?

### Late entries

19. Is late entry enabled per event, globally by event type, or both?
20. When does the late-entry window begin and end?
21. Is the late fee a fixed amount, a percentage, or configurable per event/class?
22. Is the fee charged per rider entry or once per checkout?
23. Do members and non-members pay the same late fee?
24. Are organisers/admins exempt from the late fee, or must an exemption be explicitly recorded?
25. Do late entries require organiser approval before payment or confirmation?
26. Should late entries be visibly labelled in booking records, entry lists, confirmations and finance reports?
27. What happens when an entry was added before closing but expires or is paid after closing: is that a late entry?

### Communications and audit

28. Should expiry trigger an email, or only an on-site/account notification?
29. Should successful resumption and late-fee acceptance be recorded in an audit trail?
30. Should administrators be able to restore an expired entry on behalf of a customer, and should that action reserve capacity immediately?

## Suggested implementation stages

1. Fix expiry enforcement consistently across basket, checkout and payment completion.
2. Retain expired event entry details and show them in the customer's account.
3. Add resume/revalidation using current capacity, eligibility and prices.
4. Introduce explicit capacity holds if basket items are intended to reserve places globally.
5. Add configurable late-entry windows, fees, labels and reporting after the policy questions are answered.
6. Add stricter transactional capacity allocation later if the accepted small over-capacity tolerance becomes a practical issue.

