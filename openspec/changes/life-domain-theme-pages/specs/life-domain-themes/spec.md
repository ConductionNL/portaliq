## ADDED Requirements

### Requirement: A portal groups a resident's items per life domain (REQ-LDT-001)

A portal SHALL accept `themes`, a list of `{slug, title, intro, productsLabel}`. For each theme with content for the signed-in resident, the resident menu SHALL list it under the group "Thema's", and its page SHALL show the tasks, actions and product collections of every contribution tagged with that slug. A `theme` value the portal does not declare SHALL be dropped by the normaliser.

#### Scenario: Parkeren gathers two apps
- **WHEN** dossiq tags its permit collection and its parking tasks with `theme: parkeren` and the portal declares Parkeren
- **THEN** the page Parkeren shows both under "Wat moet ik regelen" and "Mijn parkeervergunningen"

#### Scenario: An empty theme stays out of the menu
- **WHEN** a resident has no tagged tasks, actions or products for Inkomen
- **THEN** the menu does not list Inkomen

### Requirement: What you can arrange follows rules on the products you hold (REQ-LDT-002)

The block "Wat kan ik regelen" SHALL list the actions tagged with the theme. An action with a `when` condition SHALL show only when at least one of the resident's products in its collection satisfies it.

#### Scenario: Visitor hours need a visitor permit
- **WHEN** "Bezoekersuren kopen" has `when: {field: "type", op: "eq", value: "bezoekers"}` and the resident holds only a resident permit
- **THEN** "Bezoekersuren kopen" does not show

### Requirement: A resident can change a product they hold (REQ-LDT-003)

A product row SHALL show the update actions its contribution declares for that row. Choosing one SHALL open the action's form with the row's current values and SHALL write through the contribution's write path. After a successful write the products block SHALL show the new value.

#### Scenario: A new licence plate
- **WHEN** the resident chooses "Kenteken wijzigen" on her resident permit and enters GZ-519-T
- **THEN** the permit row reads "Kenteken GZ-519-T"
