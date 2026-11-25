# docusaurus-plugin-portaliq Delta: docusaurus-plugin-portaliq

**Status**: in-progress
**Scope**: docusaurus-plugin-portaliq (new project), portaliq (contract owner)
**OpenSpec changes**:

- [docusaurus-plugin-portaliq](../../)

## Purpose

A Docusaurus plugin that builds a documentation site from Portaliq's headless
content API, and by doing so continuously proves that the API is sufficient
without Portaliq's own renderer. Implements ADR-086 §1's consumer side.

## ADDED Requirements

### Requirement: The plugin MUST build a site from the public API alone

The plugin SHALL fetch a website's menu, markdown pages and glossary terms
through the public content API at build time and emit Docusaurus docs, sidebar
items and a glossary route. It SHALL use no Portaliq internal.

#### Scenario: A site is reproduced from the API

- **GIVEN** a website with a two-level menu, markdown pages and glossary terms
- **WHEN** the plugin builds
- **THEN** the site carries the same navigation structure, the same pages and
  the same glossary

#### Scenario: Only the public API is called

- **GIVEN** a build
- **WHEN** its outbound requests are inspected
- **THEN** every one is a public content API call
- **AND** this is asserted, because the plugin's whole architectural value is
  that it cannot cheat

#### Scenario: Markdown is passed through, not converted

- **GIVEN** a markdown page containing a code fence and a table
- **WHEN** the site builds
- **THEN** Docusaurus's own pipeline renders them, with no intermediate HTML
  round-trip

### Requirement: A build MUST fail loudly rather than publish incomplete content

The plugin SHALL fail the build when it cannot reach the API, when a response
is not understood, or when fewer items are returned than the configuration
declares it expects.

#### Scenario: An unreachable API fails the build

- **GIVEN** Portaliq is unreachable
- **WHEN** the site builds
- **THEN** the build fails, naming the endpoint

#### Scenario: Silent content loss is prevented

- **GIVEN** a site configured to expect a minimum page count
- **WHEN** the API returns fewer
- **THEN** the build fails
- **AND** the failure states both counts — a docs site that quietly loses half
  its pages looks exactly like one that never had them

#### Scenario: A cached snapshot is opt-in, never a silent fallback

- **GIVEN** a previous successful build's snapshot
- **WHEN** the API is unreachable and snapshot use is not enabled
- **THEN** the build fails rather than publishing stale content

### Requirement: Credentials MUST NOT reach build output

When configured with a token for non-public content, the plugin SHALL keep it
out of generated files, client bundles and logs.

#### Scenario: The token is absent from the built site

- **GIVEN** a build configured with a token
- **WHEN** the output directory is searched
- **THEN** no part of the token is present

#### Scenario: A failed fetch does not log the token

- **GIVEN** a request that fails
- **WHEN** the build log is inspected
- **THEN** it contains no part of the token

### Requirement: The plugin MUST report any capability the API cannot serve

Where a site needs content Portaliq's own renderer can show but the API cannot
supply, the plugin SHALL report it as an API gap.

#### Scenario: A gap is recorded against the API, not worked around

- **GIVEN** a capability the built-in portal renders and the API does not expose
- **WHEN** the plugin encounters it
- **THEN** it reports the gap, naming the capability
- **AND** the resolution is to extend the API, because a workaround here ends
  the headless property quietly
