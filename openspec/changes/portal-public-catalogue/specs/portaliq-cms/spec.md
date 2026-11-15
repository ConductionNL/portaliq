## ADDED Requirements

### Requirement: A catalogue block searches and filters the portal's public catalogue

A page MAY hold an `nlCatalogue` widget with `heading`, `intro`, `types`, `display` (`cards` or `dated`), `pageSize`, `sort`, `countLabel`, `searchLabel`, `placeholder`, `showSearch` and `newsRoute`. It MUST start with the words the header search put on the address (`_search`), ask the server for each search, facet choice, sort and page, and draw only what the server sends: a labelled search field, the facets as checkboxes with their counts and a way to clear them, the count ("18 resultaten voor "toetsweek""), each result with its kind, date, meta, title (a link where the item has one; a news item links to `newsRoute/<id>`), summary and note, and the pages. A read that fails MUST say search is unavailable, never "nothing found".

#### Scenario: Searching from the header
- GIVEN a visitor typed "toetsweek" in the header search
- WHEN the page with the catalogue block opens
- THEN the block asks the server for "toetsweek" and shows its count

### Requirement: A dated list may fill itself from the catalogue

An `nlEventList` widget MAY declare `source: {types: [...]}`. It MUST then show the catalogue's upcoming dated items of those types, earliest first, instead of its authored items; when the read fails the authored items stay.

#### Scenario: The academy's next course days
- GIVEN an `nlEventList` with `source: {types: ["course"]}`
- WHEN the home page opens
- THEN the list shows the courses still to come with their first day
