## ADDED Requirements

### Requirement: An app may offer a portal an index of its public things

An app MAY implement `getPublicIndex(string $portal): array`. Portaliq MUST hold every answer to `{id, type, kind, title, summary?, date?, endDate?, dateLabel?, meta?, facets?, note?, noteTone?, href?, badge?}`: an entry without an id, a lower-camel `type`, a `kind` or a `title` MUST be dropped; markup MUST be stripped and lengths capped; a date that does not parse, an `href` that is not a site path or an http(s) address, and any other key MUST be dropped. An app that fails MUST add nothing while the rest still count. `getPublicIndex` MUST NOT be accepted as a manifest provider name.

#### Scenario: A script link never reaches a visitor
- GIVEN an app item with `href: "javascript:alert(1)"` and an unknown key `secret`
- WHEN the catalogue gathers it
- THEN the item has neither an `href` nor a `secret`

### Requirement: A visitor may search and filter a portal's public catalogue

`GET /api/content/catalogue` MUST answer, for the resolved portal, one page of its public news and every app's index for that portal, after the portal's sign-in modes allow it (a portal without `public` answers only a session that meets its trust; an unknown portal is the shared 404). Every word of `search` MUST occur in an item's title, kind, summary or meta, case and accents ignored. `types` MUST keep only those types. `filters` MUST keep the items that hold one of the chosen values of every chosen facet. Each facet MUST be returned with each value's count among the items that match every OTHER facet's choice. `upcoming` MUST keep only items whose (end) date is today or later. The answer MUST carry `items`, `total`, `page`, `pages` and `facets`, at most 50 items a page.

#### Scenario: The academy's courses by start month
- GIVEN three courses, two starting in November 2026
- WHEN a visitor chooses "Start in: November 2026"
- THEN two courses are returned and the "Start in" facet still counts October

#### Scenario: A closed portal refuses a visitor
- GIVEN a portal whose sign-in modes are only `digid`
- WHEN a visitor without a session asks its catalogue
- THEN the answer is 401
