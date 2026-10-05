## ADDED Requirements

### Requirement: A per-record page in a group lists each row with its subtitle

When a contributed page declares `perRecord` and `group`, the resident menu MUST list each row of
that collection as one item of the group, named by the row's title fields, linking to the page with
that row chosen, with the row's `records.subtitleFields` joined by " · " as a second line. Without
`group` it MUST keep one group per row.

#### Scenario: The children of a guardian
@e2e exclude Rendered in node: tests/resident-menu-badges.spec.mjs
- GIVEN a page "Mijn kind" in group "Mijn kinderen", per record of the children, subtitle fields cohort and teacher
- WHEN the menu renders for Vera (Groep 7, Meester Daan) and Sami (Groep 4)
- THEN "Mijn kinderen" holds "Vera" with "Groep 7 · Meester Daan" and "Sami" with "Groep 4"

### Requirement: A menu entry may show the count of a collection

When a page declares `badge.collection`, one of its contribution's collections, the menu MUST
load that collection's rows for the resident and show their number beside the entry when it is
more than 0, with `badge.label` (`{count}` replaced) as its text for screen readers. The phone
button MUST show the total of all counts.

#### Scenario: Conferences to plan
@e2e exclude Rendered in node: tests/resident-menu-badges.spec.mjs
- GIVEN a page "Oudergesprekken" with a badge on a collection holding one row
- WHEN the menu renders
- THEN the entry shows "1" and reads "1 te plannen"

#### Scenario: Rows not known yet
@e2e exclude Rendered in node: tests/resident-menu-badges.spec.mjs
- GIVEN the badge collection has not loaded
- WHEN the menu renders
- THEN the entry shows no count

### Requirement: The menu may open with whom the resident acts for

When the portal declares `residentMenu.cardLabel` and the session acts for an organisation
(`organisationName`), the menu MUST open with a card showing the label and the organisation's
name. Otherwise it MUST show no card.

#### Scenario: An employer
@e2e exclude Rendered in node: tests/resident-menu-badges.spec.mjs; PortalShell projection by PHPUnit
- GIVEN a portal with card label "U regelt het voor" and a session for Jansen Installatietechniek BV
- WHEN the menu renders
- THEN it opens with "U regelt het voor" and "Jansen Installatietechniek BV"
