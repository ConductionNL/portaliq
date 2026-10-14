## ADDED Requirements

### Requirement: A link to a record or a search result keeps the portal and the route (REQ-RSL-001)
A link the site writes to one record on its list page (`record`), to a search result and to an intake form MUST be built in the addressing of the host (`addressOfRoute`): the `portal` and `lang` of the current address MUST stay, and a record link MUST keep the route of the page it is listed on.

#### Scenario: A council meeting from the list on a shared host
@e2e exclude Node: tests/record-and-search-links.spec.mjs
- GIVEN the Zuiddrecht site is served as `?portal=zuiddrecht&route=/bestuur/vergaderingen`
- WHEN a visitor opens a meeting from the list
- THEN the link carries `portal=zuiddrecht`, `route=/bestuur/vergaderingen` and `record=<id>`

#### Scenario: A search result on a host that serves the site at its root
@e2e exclude Node: tests/record-and-search-links.spec.mjs
- GIVEN the site is served at the root of its host
- WHEN a visitor opens a search result
- THEN the link is the short address of the result's page
