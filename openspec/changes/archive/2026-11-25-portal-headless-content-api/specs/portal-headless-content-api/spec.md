# portal-headless-content-api Delta: portal-headless-content-api

**Status**: in-progress
**Scope**: portaliq
**OpenSpec changes**:

- [portal-headless-content-api](../../)

## Purpose

The public content API that makes Portaliq a headless CMS: site, menu, pages,
glossary and media, with audience-correct caching and event-driven
invalidation. Implements ADR-086 §§1 and 9. Related: ADR-082 (throttling),
ADR-005 (fail-closed), ADR-022 (reads via OpenRegister).

## ADDED Requirements

### Requirement: The API MUST expose a site's navigation, pages, glossary and media

The public API SHALL expose, for the resolved website: the site's own
presentation record, its menu tree, a page by route, a page listing, glossary
terms, and media references. Every response SHALL be scoped to the resolved
website.

#### Scenario: A page is retrievable by its route

- **GIVEN** a published page at `/contact`
- **WHEN** it is requested anonymously for that website
- **THEN** the page is returned with its title, metadata and body

#### Scenario: An unpublished page is not served

- **GIVEN** a page whose status is not published
- **WHEN** it is requested anonymously
- **THEN** it is not returned, and the response does not distinguish it from a
  route that does not exist

#### Scenario: The menu tree preserves order and nesting

- **GIVEN** a menu with ordered two-level items
- **WHEN** it is read
- **THEN** the order and nesting are preserved as stored

### Requirement: A markdown page MUST be served as markdown

A page with a `markdown` body SHALL be returned as markdown source. The API
SHALL NOT convert it to HTML.

#### Scenario: The consumer receives source

- **GIVEN** a markdown page
- **WHEN** it is read through the API
- **THEN** the response carries the markdown source, byte-identical to what was
  stored

#### Scenario: A grid page returns its widget placements

- **GIVEN** a grid page
- **WHEN** it is read
- **THEN** its widget entries are returned in the canonical
  `$defs.widgetEntry` shape

### Requirement: Responses MUST be cached with the audience in the key

Public content responses SHALL be cached by website, route, locale and
audience. Per-visitor responses SHALL be marked `private, no-store`; anonymous
published content SHALL be marked publicly cacheable.

#### Scenario: Anonymous and authenticated responses do not share an entry

- **GIVEN** one route requested anonymously and authenticated
- **WHEN** both are served from cache
- **THEN** each receives its own variant

#### Scenario: The audience component is load-bearing

- **GIVEN** the audience component removed from the cache key
- **WHEN** the separation test runs
- **THEN** it FAILS — the control that proves the key does the work, observed
  before the key is trusted

#### Scenario: Headers permit only the sharing that is safe

- **GIVEN** a per-visitor response and an anonymous published response
- **WHEN** their headers are inspected
- **THEN** the first forbids shared caching and the second permits it, each
  stated once with no contradictory second directive

### Requirement: A write MUST invalidate the affected cache entries

A content object's write SHALL invalidate the entries derived from it, without
waiting for expiry.

#### Scenario: A publish is visible on the next request

- **GIVEN** a cached page
- **WHEN** the page object is updated
- **THEN** the next request returns the new content

#### Scenario: A menu change invalidates the pages that render it

- **GIVEN** cached pages for a website
- **WHEN** its menu is updated
- **THEN** the affected entries are invalidated

#### Scenario: Invalidation is scoped to the website

- **GIVEN** two websites with cached content
- **WHEN** one site's page is updated
- **THEN** the other site's entries are untouched

### Requirement: The anonymous read path MUST be throttled

Public content endpoints SHALL be rate-limited per source.

#### Scenario: Excess anonymous reads are refused

- **GIVEN** requests from one source exceeding the configured rate
- **THEN** further requests are refused
- **AND** the refusal is confirmed by two independent discriminators, because
  an absent success is not by itself evidence that throttling fired

#### Scenario: A cache hit does not bypass the limit

- **GIVEN** a route already cached
- **WHEN** requests exceed the rate
- **THEN** they are still refused — serving from cache is not an exemption

### Requirement: The API MUST be sufficient for a renderer that is not ours

Every capability of the built-in portal SHALL be reachable through the public
API. A conformance check SHALL fail when one is not.

#### Scenario: An external consumer reproduces a site

- **GIVEN** a website with a menu, markdown pages and glossary terms
- **WHEN** an external consumer builds from the public API alone
- **THEN** it reproduces the navigation, pages and glossary
- **AND** it reaches into no Portaliq internal

#### Scenario: A renderer-only capability is reported

- **GIVEN** a capability present in the built-in portal and absent from the API
- **WHEN** the conformance check runs
- **THEN** it fails, naming the capability
