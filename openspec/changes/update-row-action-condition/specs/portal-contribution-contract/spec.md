---
status: proposed
---

# Spec: portal-contribution-contract

## Purpose

A leaf app says on which rows a `type: update` row action applies, with the
same `rowWhen` condition the endpoint row actions use. The condition decides
what the screen shows; it never decides what the server allows.

## ADDED Requirements

### Requirement: An update row action MUST be shown only on the rows its rowWhen names (REQ-URC-001)

A `type: update` row action MAY declare `rowWhen`: `{field, in}` with `field` a
field name and `in` a non-empty list of scalar values. The site SHALL show the
action's button only on a row whose `field` holds one of the listed values. An
update action without `rowWhen` SHALL be shown on every row.

The condition is presentation only. It SHALL NOT be treated as authorisation:
the update itself remains subject to the subject's scope, the action's
`fields` and `set`, and the leaf app's own lifecycle checks, which refuse a
transition the row does not allow whatever `rowWhen` says.

#### Scenario: A guardian sees the cancel only on a time that can still be cancelled
- **GIVEN** learniq's `cancelConferenceTime` with `rowWhen: {field: lifecycle, in: [booked, acknowledged]}`
- **WHEN** the guardian's conference times hold a booked, an acknowledged, a completed, a cancelled and a declined time
- **THEN** only the booked and the acknowledged row show "Cancel this time"
- @e2e exclude pinned by `tests/row-action.spec.mjs` ("an update row action follows its rowWhen too" and "the table shows the cancel button only on a booked or acknowledged time", the site's CollectionTable rendered); live-checked as a guardian on the primary-school instance

#### Scenario: The server is not asked for permission by the screen
- **GIVEN** the same action and a cancelled time
- **WHEN** a request to cancel that time is sent past the screen
- **THEN** learniq refuses it as it did before; portaliq adds no check of its own
- @e2e exclude The refusal is learniq's (ConferenceSlotBookingSync and its tests); portaliq only stops offering the button.

### Requirement: A malformed row condition MUST be dropped with a warning (REQ-URC-002)

Portaliq SHALL check every action's `rowWhen` before the manifest reaches a
renderer. A key other than `field` and `in` SHALL be dropped and logged as a
warning that names the app and the action. A `rowWhen` on a `type: update`
action that is not an object, has no valid field name, or has an `in` that is
not a non-empty list of scalars SHALL be dropped whole and logged, so the
action is shown on every row as without a condition. On an endpoint row action
a malformed `rowWhen` SHALL be left for the row-action resolver, which keeps the
action from resolving as a row action.

#### Scenario: An unknown operator is dropped and the rest stays
- **GIVEN** `rowWhen: {field: lifecycle, in: [booked], before: bookingClosesAt}` on an update action
- **WHEN** the contribution is normalised
- **THEN** the action keeps `rowWhen: {field: lifecycle, in: [booked]}` and a warning names `before`
- test: PHPUnit `tests/Unit/Contribution/RowWhenNormaliserTest.php` ("drops an unknown operator and keeps the rest")

#### Scenario: A malformed condition on an update action is dropped
- **GIVEN** `rowWhen: {field: lifecycle, in: []}` on an update action
- **WHEN** the aggregate is built
- **THEN** the action has no `rowWhen` and a warning is logged with the app
- test: PHPUnit `tests/Unit/Contribution/RowWhenNormaliserTest.php` ("drops a malformed condition on an update action") and `tests/Unit/Contribution/PortalContributionRegistryTest.php` ("a malformed row condition is dropped from the aggregate")
