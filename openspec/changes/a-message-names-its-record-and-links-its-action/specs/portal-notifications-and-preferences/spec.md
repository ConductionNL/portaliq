## ADDED Requirements

### Requirement: A notice may name its sender's role, what it is about, and one action

An inbox collection MAY name a sender role field, an about field with an optional link field, and an action field holding a label and a page address. The site MUST show the role under the sender's name, "Over:" with the about value (as a link when given), and on the opened notice one button with the action's label. A button whose address is not a page of the same portal MUST NOT be rendered.

#### Scenario: Petra's question about Tuesday
- **GIVEN** learniq projects sender role "Praktijkopleider, Bakker Techniek BV", about "Uren week 40" and action `{label: Uren aanpassen, href: /mijn/bpv-uren/2026-09-29}`
- **WHEN** Milan opens the notice
- **THEN** he reads the role line, "Over: Uren week 40", and the button "Uren aanpassen" that opens that day
- @e2e exclude spec-only proposal; component asserted in `node --test`, flow in learniq's `tests/e2e/portal-design/esdoornveen.spec.ts`

#### Scenario: An address outside the portal
- **GIVEN** an action whose address is `https://example.org/pay`
- **WHEN** the notice is opened
- **THEN** no button is shown
- @e2e exclude guard asserted in `node --test`

### Requirement: The inbox may be filtered by tabs and marked read at once

The inbox page MAY declare tabs: all, unread with its count, and one tab per declared value of a collection field. "Alles als gelezen markeren" MUST mark only the shown messages read, through each collection's own read field.

#### Scenario: Unread (2)
- **GIVEN** Milan has two unread notices of six
- **WHEN** he opens Berichten
- **THEN** the tab reads "Ongelezen (2)" and shows those two
- @e2e exclude spec-only proposal
