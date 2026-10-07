# case-timeline Specification

## Purpose
A resident follows what happened on their case: every step the case app chose
to publish, newest first, on the case's detail card and on a record page's
timeline block. The case app decides what is public; portaliq proves the case
is the resident's, asks the app, and shows the answer without adding to it or
filtering it. Written after the fact (7 Oct 2026) for parity row
`cmp-cas-status-timeline`, shipped with portaliq#732 (fixes #723) without a
change. It describes the code on development: `lib/Controller/PortalTimelineController.php`,
`lib/Service/PortalTimelineReader.php`, `lib/Contribution/TimelineProviderMethod.php`,
`src/site/components/collections/TimelineList.vue`, `src/site/components/collections/timeline.js`
and `src/site/components/mijn/TimelineBlock.vue`. The case's steps
(`steps`, "Waar staat uw aanvraag?") are specified by the open change
`site-mijn-omgeving-components`, not here.

## Requirements

### Requirement: A collection names its own timeline method (REQ-CTL-001)

A contribution collection MAY declare `timeline: {label?, provider}`, where
`provider` names a method on the contributing app's portal provider. Portaliq
SHALL accept a provider name only when it starts with a lowercase letter, holds
only letters and digits, and is not one of the contract's own methods
(`getContribution`, `getAudience`, `getAudiences`, `getPublicIndex`). A
collection without an accepted provider SHALL have no timeline.

#### Scenario: dossiq declares its case history
- **GIVEN** dossiq's case collection declares `timeline: {label: "Wat er is gebeurd", provider: "caseTimeline"}`
- **WHEN** portaliq reads the contribution
- **THEN** the collection keeps the timeline and its label

#### Scenario: A manifest cannot make portaliq call the contract
- **GIVEN** a collection declaring `timeline: {provider: "getContribution"}`
- **WHEN** a resident asks for the timeline of one of its records
- **THEN** the answer is 404 `not_found` and no provider method is called

### Requirement: Only the resident's own record has a timeline (REQ-CTL-002)

`GET /portal/api/collections/{register}/{schema}/{id}/timeline` SHALL require a
portal bearer, SHALL find the collection among the contributions the subject may
read (narrowed by the `collection` query parameter when given), SHALL hold the
session's trust to the collection's `minTrust`, and SHALL read the record through
the same scoped read as a single object before it calls the provider. A missing
bearer SHALL answer 401, a collection the subject may not read or a trust too low
SHALL answer 403, and a record outside the subject's scope SHALL answer 404.

#### Scenario: Another resident's case
- **GIVEN** a signed-in resident and a case whose scope field names someone else
- **WHEN** they request that case's timeline
- **THEN** the answer is 404 and the provider is not called

#### Scenario: No session
- **WHEN** a request without a bearer asks for a timeline
- **THEN** the answer is 401 with `authenticated: false`

### Requirement: The app's answer is passed on as it came (REQ-CTL-003)

For a proven record the endpoint SHALL call the declared method with the record
id and SHALL answer `{label, entries}` with the entries the method returned,
adding and dropping none. When the app is not installed, the method is not
callable, the call throws or the answer is not a list, the endpoint SHALL answer
502 `timeline_unavailable` and log a warning naming the app and the method.

#### Scenario: Two entries come back
- **GIVEN** dossiq's `caseTimeline` returns two entries for a case
- **WHEN** the resident's case is read
- **THEN** the answer carries both entries under the declared label

#### Scenario: The case app fails
- **GIVEN** the provider method throws
- **WHEN** the timeline is requested
- **THEN** the answer is 502 `timeline_unavailable`

### Requirement: The resident reads the history newest first (REQ-CTL-004)

The site SHALL show a collection's timeline on the record's detail card under
the declared label (or "What happened"), and a record page's `timeline` block
SHALL show the open record's timeline. Entries SHALL be ordered newest first by
`occurredAt` or `date`, entries without a moment last, keeping the app's order
among equals. Each entry SHALL read its `message`, else its `label`, else its
`title`. An empty list SHALL read "Nothing has happened yet."; on the timeline
block a failed read SHALL show "What happened could not be loaded." with a
"Try again" button.

#### Scenario: Newest first
- **GIVEN** a timeline with an entry of 1 September and one of 3 September
- **WHEN** the resident opens the case
- **THEN** the 3 September entry is shown above the 1 September entry

#### Scenario: Nothing yet
- **GIVEN** the provider returns an empty list
- **WHEN** the resident opens the case
- **THEN** they read "Nothing has happened yet."
