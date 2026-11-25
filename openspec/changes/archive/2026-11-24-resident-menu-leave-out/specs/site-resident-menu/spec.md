## ADDED Requirements

### Requirement: A portal may leave items out of the resident menu

A portal MAY declare `residentMenu.leaveOut`, a list of item names. The site MUST leave those
items out of the resident menu and drop a group that is then empty. `overview` MUST always stay.
Without the key the menu MUST be built as before.

#### Scenario: A school portal without "Mijn zaken en verzoeken"
@e2e exclude Unit test in node: tests/site-look/resident-menu-leave-out.spec.mjs; PHPUnit PortalShellTest
- GIVEN a portal with `residentMenu.leaveOut` `["cases", "tasks", "access"]`
- WHEN a signed-in guardian opens the own area
- THEN the menu has no "Mijn zaken en verzoeken" group, and every other group is as before

#### Scenario: Nothing declared
@e2e exclude Unit test in node: tests/site-look/resident-menu-leave-out.spec.mjs
- GIVEN a portal without `residentMenu.leaveOut`
- WHEN the menu is built
- THEN it holds the "Mijn zaken en verzoeken" group as before
