## ADDED Requirements

### Requirement: A search from the home page opens the results (REQ-RSP-007)

A search field placed on a page, such as the home page hero, SHALL open the portal's search page with the term filled in and its results shown.

#### Scenario: A visitor searches from the home page
- **WHEN** a visitor types "afvalkalender" in the home page search field and presses Enter
- **THEN** the search page opens with "afvalkalender" in its field and the results for that term

### Requirement: Results can be sorted by date (REQ-RSP-008)

The search block SHALL let a visitor sort results by publication date, newest first, next to sorting by relevance. The chosen order SHALL travel in the page address.

#### Scenario: Newest first
- **WHEN** a visitor searching for "subsidie" chooses "Newest first"
- **THEN** the results are ordered by publication date, newest first, and the page address carries that order
