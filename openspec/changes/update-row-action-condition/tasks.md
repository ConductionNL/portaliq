# Tasks: update-row-action-condition

- [x] **T1**: `offersRowAction()` applies `rowWhen` to `type: update` row actions too, and the site's table shows the button only on the matching rows (REQ-URC-001).
  - `node --test tests/row-action.spec.mjs` ("an update row action follows its rowWhen too", "the table shows the cancel button only on a booked or acknowledged time")
- [x] **T2**: `RowWhenNormaliser` drops an unknown operator and a malformed update condition with a warning, and the registry runs it on every contribution (REQ-URC-002).
  - PHPUnit `tests/Unit/Contribution/RowWhenNormaliserTest.php`
  - PHPUnit `tests/Unit/Contribution/PortalContributionRegistryTest.php` ("a malformed row condition is dropped from the aggregate")
- [ ] **T3**: Live: as a guardian on "Uw gesprekstijden" the cancel shows only on a booked or acknowledged time (learniq declares the condition). — not run: needs a live instance and learniq declaring the condition
