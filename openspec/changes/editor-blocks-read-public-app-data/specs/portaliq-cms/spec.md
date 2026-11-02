## ADDED Requirements

### Requirement: A page block may read an app's public index, with options the app declares

The `nlEventList` block MAY declare a `source` naming an app, an index kind, categories and a limit or range; a new `nlPublicTable` block MAY declare a `source` with an app, an index kind, filters and columns. Portaliq MUST fill both from that app's public index for the portal, MUST offer in the editor only the kinds, categories, filters and columns the app declares, and MUST NOT let an editor change the data. A filter value `visitor` MUST resolve from a signed-in visitor's own record and MUST stay empty for an anonymous visitor.

#### Scenario: Toetsrooster 4 havo
- **GIVEN** an editor placed `nlPublicTable` with learniq's test schedule, filter 4 havo and toetsweek 1, columns day, time, subject, room
- **WHEN** a visitor opens the page
- **THEN** the table lists the 4 havo tests of 9 to 13 November, and a sitting moved by the roostermaker shows its new day after the index refreshes
- @e2e exclude spec-only proposal; component asserted in `node --test`, page in learniq's `tests/e2e/portal-design/vaartveld.spec.ts`

#### Scenario: Holidays and days off only
- **GIVEN** a calendar block with categories holiday and day off, "dit schooljaar"
- **WHEN** a visitor opens "Schooltijden en vakanties"
- **THEN** it lists the school's holidays and study days, not its activities
- @e2e exclude as above
