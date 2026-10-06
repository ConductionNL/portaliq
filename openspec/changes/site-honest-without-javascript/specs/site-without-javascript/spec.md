## ADDED Requirements

### Requirement: Every site page says so when JavaScript is off (REQ-SHJ-001)

Every response of `GET /site` SHALL carry, directly after the skip link, a `<noscript>` block that holds a
`<main id="pq-main">` with a notice in the document language. The notice SHALL say that the site uses JavaScript for
its interactive parts and SHALL link to the plain version of the same page: `/site/plain` with the request's `route`,
`portal`, `_search` and `_page` parameters carried over. With JavaScript off, the skip link SHALL land on that
`main`. The notice SHALL be translated (Dutch and English at least) and SHALL NOT be shown when JavaScript runs.

#### Scenario: A visitor without JavaScript is told and given a way on
- **GIVEN** a portal with a published `/zoeken` page and a browser with JavaScript disabled
- **WHEN** the visitor opens `/site?route=/zoeken&_search=afval`
- **THEN** the page SHALL show the notice in Dutch
- **AND** its link SHALL point to `/site/plain?route=/zoeken&_search=afval`
- **AND** following the skip link SHALL move focus into the `main` that holds the notice

#### Scenario: A visitor with JavaScript sees no notice
- **GIVEN** the same page in a browser with JavaScript enabled
- **WHEN** the site has rendered
- **THEN** the notice SHALL NOT be visible and the page SHALL contain exactly one element with id `pq-main`

### Requirement: The server renders a plain version of every public page (REQ-SHJ-002)

`GET /site/plain` SHALL be public, rate limited like `/site`, and SHALL resolve the portal and the route the way
`/site` does. It SHALL render, without any `<script>` element, with the same stylesheets as `/site`: the portal's
title, its main menu as links into `/site/plain`, the page's title, summary and body, and a link to the full page.
A markdown body SHALL be rendered by a server-side converter for a fixed subset (headings, paragraphs, lists, links,
emphasis, code) that escapes all other input and allows only `http`, `https`, `mailto` and relative link targets.
A grid body SHALL render the text of `markdown`, `nlHeading`, `nlParagraph`, `nlList` and `nlLinkList` widgets, the
publication widgets as REQ-SHJ-003 and REQ-SHJ-004 say, and for every other widget one sentence saying that this
part needs JavaScript, with its label and a link to the full page. The page SHALL be read through `CmsReader` with
the anonymous audience, also when the request carries a session. A route without a published page SHALL answer
404 with the portal's not-found text. The page SHALL carry `<link rel="canonical">` to the `/site` address of the
same route.

#### Scenario: A text page reads the same without JavaScript
- **GIVEN** the published page `/over-ons` the e2e suite already reads (`site-accessibility.spec.ts`)
- **WHEN** an anonymous visitor opens `/site/plain?route=/over-ons` with JavaScript disabled
- **THEN** the response SHALL be 200, contain the heading and the body text and the main menu links, and contain no `<script>` element

#### Scenario: A part that needs JavaScript says so
- **GIVEN** a page with an `intakeForm` widget
- **WHEN** its plain version is rendered
- **THEN** in that widget's place the page SHALL say that this part needs JavaScript and link to the full page, and SHALL NOT leave the place empty

#### Scenario: A draft or a session never leaks
- **GIVEN** a draft page at `/concept` and a request carrying a signed-in resident's session
- **WHEN** `/site/plain?route=/concept` is requested
- **THEN** the response SHALL be 404, the same as for a route that does not exist

#### Scenario: Markdown cannot inject markup
<!-- @e2e exclude Unit level: PlainMarkdownTest feeds the converter hostile input; a browser adds nothing to that proof. -->
- **GIVEN** a markdown body containing `<script>alert(1)</script>` and a link to `javascript:alert(1)`
- **WHEN** it is rendered by the plain version
- **THEN** the script SHALL appear as escaped text and the link SHALL NOT carry the `javascript:` target

### Requirement: Publications can be searched without JavaScript (REQ-SHJ-003)

On a page with a `federatedSearch` widget the plain version SHALL render a form with `method="get"` and
`action` `/site/plain`, a hidden `route`, a labelled search field named `_search` and a submit button; and below it
the results of the widget's endpoint for `_search` and `_page` with the widget's page size: per result the title
linking to `/site/plain?route=<detailRoute>/<id>`, the date and the summary; the total; and previous and next links.
The server SHALL read the endpoint through `InstanceLoopback` with no cookie and no authorization header. It SHALL
only call an endpoint that is a relative path on this instance; for an absolute endpoint it SHALL NOT make the call
and SHALL render the notice of REQ-SHJ-005.

#### Scenario: A search works with JavaScript off
- **GIVEN** opencatalogi with a published publication titled "Woo-besluit afvalinzameling 2026"
- **WHEN** a visitor with JavaScript disabled types "afvalinzameling" in the plain search form on `/zoeken` and submits it
- **THEN** the result list SHALL show that publication with its date, and its title SHALL link to its plain detail page

#### Scenario: Paging works with JavaScript off
<!-- @e2e exclude Unit level: PlainPublicationSearchTest serves a 25-row double; seeding 25 publications in CI adds time and no further proof. -->
- **GIVEN** an endpoint that answers 25 results at page size 10
- **WHEN** the plain search renders page 2
- **THEN** it SHALL show the total 25, results 11 to 20, and links to pages 1 and 3

#### Scenario: The server does not fetch a foreign endpoint
<!-- @e2e exclude Unit level: PlainPublicationReaderTest asserts the loopback is never called; a browser cannot observe a call that was not made. -->
- **GIVEN** a `federatedSearch` widget whose endpoint is `https://elders.example/api/publications`
- **WHEN** the plain version is rendered
- **THEN** no request SHALL be made to that address and the page SHALL show the notice with a link to the full page

### Requirement: A publication can be read without JavaScript (REQ-SHJ-004)

For a route that resolves to a page with a `publicationDetail` widget and a trailing segment, the plain version SHALL
read `<endpoint>/<id>` and `<endpoint>/<id>/attachments` anonymously through `InstanceLoopback` and render the title,
the summary, the publication date, the information category and the themes by name, and each document as a link
that downloads it, with its name, type and size. A publication that does not exist and one the anonymous caller may
not read SHALL both answer 404 with the same text.

#### Scenario: A publication and its documents open without JavaScript
- **GIVEN** the publication "Woo-besluit afvalinzameling 2026" with one PDF document
- **WHEN** a visitor with JavaScript disabled follows its title from the plain search
- **THEN** the page SHALL show the title, date and category, and a link to the PDF that downloads it

#### Scenario: Missing and withheld look the same
- **GIVEN** an id that does not exist and an id of an unpublished publication
- **WHEN** each plain detail page is requested
- **THEN** both SHALL answer 404 with identical bodies apart from the requested address

### Requirement: When publications cannot be read, the page says so (REQ-SHJ-005)

When opencatalogi is not installed, the endpoint answers an error or does not answer within five seconds, or the
endpoint is not on this instance, the plain version SHALL render, in place of the publication widget, a sentence that
the publications cannot be shown here right now and a link to the full page, and SHALL render the rest of the page.
It SHALL NOT render an empty result list, a total of zero, or a "nothing found" text in that case.

#### Scenario: opencatalogi is absent
<!-- @e2e exclude Unit level: PlainPublicationReaderTest with the app manager reporting opencatalogi disabled; the CI e2e instance has it installed. -->
- **GIVEN** opencatalogi is not installed
- **WHEN** the plain version of `/zoeken` is rendered with `_search=afval`
- **THEN** the page SHALL say the publications cannot be shown here right now, and SHALL NOT say that nothing was found
