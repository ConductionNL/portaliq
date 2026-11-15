## ADDED Requirements

### Requirement: Error reports are opt-in and need a named owner (REQ-PER-001)

`#portal` SHALL declare `publicationErrorReports` (boolean, default false) and
`publicationErrorReportOwner` (a Nextcloud user id or group id). The setting SHALL be refused unless the
owner names an existing user or group. While it is false, no publication page SHALL offer "Fout
melden" and the report route SHALL answer 404.

#### Scenario: Off by default
- **GIVEN** a new portal
- **WHEN** a visitor opens a publication
- **THEN** no "Fout melden" SHALL be offered, and a POST to the report route SHALL answer 404

#### Scenario: No owner, no switch
- **GIVEN** an administrator with no owner set
- **WHEN** they switch error reports on
- **THEN** the save SHALL be refused, naming the missing owner

### Requirement: A report reaches the named owner (REQ-PER-002)

When on, POST `/index.php/apps/portaliq/api/site/publication-reports` with
`{portal, publication, document?, description, contactEmail?}` SHALL store a `publicationErrorReport`
with those fields, `receivedAt` and status `new`, SHALL send the owner (each member, for a group) a
Nextcloud notification linking to the report, and SHALL answer 201 with a short receipt sentence. The
route SHALL be public, SHALL be rate limited per address and per publication, SHALL refuse a
description longer than 2000 characters or a publication the anonymous public read does not return,
and SHALL NOT store the IP address or the user agent. The portal admin SHALL list the reports for the
owner, who SHALL mark each `handled` or `not-applicable`. A contact e-mail SHALL be visible to the owner
only and SHALL be erased 30 days after the report is closed.

#### Scenario: A wrong version reaches the owner
- **GIVEN** error reports on with owner group "woo-redactie"
- **WHEN** an anonymous visitor reports "Bijlage 2 is de conceptversie" on a public publication
- **THEN** a `publicationErrorReport` SHALL exist with status `new`, and each member of "woo-redactie" SHALL have a notification linking to it

#### Scenario: A flood is throttled
- **GIVEN** error reports on
- **WHEN** one address posts 20 reports in a minute
- **THEN** the route SHALL refuse beyond its rate limit and SHALL store none of the refused ones

#### Scenario: A report on a draft is refused
- **GIVEN** a draft publication
- **WHEN** a report names it
- **THEN** the route SHALL answer 404 and store nothing

### Requirement: Withheld notices are opt-in (REQ-PER-003)

`#portal` SHALL declare `showWithheldNotices` (boolean, default false). While false, a withdrawn
publication's link SHALL render the site's not-found page and the publication page SHALL list no
withheld documents.

#### Scenario: Off, nothing disclosed
- **GIVEN** `showWithheldNotices` false and a withdrawn publication
- **WHEN** a visitor opens its link
- **THEN** the not-found page SHALL render and no reason or date SHALL be shown

### Requirement: A withdrawn link says it was withdrawn, and why (REQ-PER-004)

When on, and the publication read answers 410 with `{id, withdrawnAt, publicReason, title?}`, the site
SHALL render a page with status 410 that says the publication was withdrawn on `withdrawnAt`, gives
`publicReason`, and shows `title` only when the answer carries it. It SHALL show nothing else of the
record.

#### Scenario: The tombstone page
- **GIVEN** `showWithheldNotices` true and a publication withdrawn on 2026-10-01 with public reason "Gepubliceerd in strijd met de AVG" and no title
- **WHEN** a visitor opens its link
- **THEN** the page SHALL answer 410 and say "Deze publicatie is ingetrokken op 1 oktober 2026" with that reason, and no title

### Requirement: A publication lists what was held back, with the ground (REQ-PER-005)

When on, and the public read of a publication carries its withheld documents with their grounds, the
publication page SHALL list them under "Niet openbaar gemaakt", each with its reference and the code
and label of each ground. It SHALL show only what the read carries.

#### Scenario: Two documents held back
- **GIVEN** `showWithheldNotices` true and a publication read carrying two withheld documents, one on 5.1.2.e and one on 5.2.1
- **WHEN** a visitor opens it
- **THEN** the page SHALL list both with "5.1.2.e" and "5.2.1" and their labels
