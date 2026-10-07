# portal-my-cases Specification

## Purpose
A signed-in person sees all their cases in one list, open and closed, including
the cases of the organisations they act for, and can switch whom they act for.
Requested by the portaliq parity matrix rows `cas-mycases-unified`,
`cas-mandate-org-cases`, `cmp-cas-all-gov`, `cmp-cas-closed` and
`cmp-sig-machtiging`.

## Requirements

### Requirement: Your cases from every app in one list (REQ-CMC-001)

The portal SHALL offer a "My cases" page listing, newest first, every case from
every contribution collection declared as `kind: cases` that the person may
read, each row naming the app and organisation it comes from.

#### Scenario: Cases from two apps in one list
- **GIVEN** a resident with one case in dossiq and one in pipelinq, both contributed as `kind: cases`
- **WHEN** they open "My cases"
- **THEN** both cases are in one list, newest first, each naming its source

#### Scenario: Nothing to show
- **GIVEN** a resident with no case in any contribution
- **WHEN** they open "My cases"
- **THEN** they read "No cases yet."

### Requirement: Open and closed cases are told apart by a declared field (REQ-CMC-002)

A contribution collection MAY declare `closedField`. A case SHALL count as closed
when that field is present and not empty, and as open otherwise. The page SHALL
show open and closed cases on separate tabs with their counts. A collection
that declares no closed marker SHALL have all its cases shown as open.

#### Scenario: A decided case moves to Closed
- **GIVEN** a collection declaring `closedField: endDate` and a case with an end date
- **WHEN** the resident opens "My cases"
- **THEN** that case is on the "Closed" tab and not on "Open"

### Requirement: A case you see through a mandate says why (REQ-CMC-003)

A case the person may read because of a mandate SHALL show that mandate's label.

#### Scenario: A mandated case carries its label
- **GIVEN** a business user acting under the mandate "Bakkerij Jansen BV"
- **WHEN** they open "My cases"
- **THEN** each of the company's cases shows "Bakkerij Jansen BV"

### Requirement: You choose whom you act for (REQ-CMC-004)

When the person holds one or more mandates, the portal header SHALL offer
"Acting for" with themself and each mandate. The choice SHALL apply to the case
list and to every case screen for the rest of the session. Acting for themself
SHALL list only their own cases. A case opened under a mandate SHALL be shown
read-only, naming the mandate, and no write SHALL be accepted under it. A
mandate that reaches more cases than the portal lists SHALL be refused with a
message, never shown as a partial list.

#### Scenario: Switching to a mandate
- **GIVEN** a resident who holds a mandate for their father
- **WHEN** they choose their father's mandate under "Acting for"
- **THEN** "My cases" lists the father's cases with the mandate's label, and opening one shows it under that mandate

#### Scenario: A mandated case is read, not changed
- **GIVEN** a business user who opened a company case under the mandate "Bakkerij Jansen BV"
- **WHEN** the case screen shows it
- **THEN** they read "You are viewing this case on behalf of Bakkerij Jansen BV. It cannot be changed here." and no change, document or withdrawal is offered

#### Scenario: Too large to list
- **GIVEN** a mandate whose party tree is larger than the portal's bound
- **WHEN** the person chooses it
- **THEN** they read "This organisation has too many cases to list here. Choose a narrower mandate." and no partial list

### Requirement: A case opens where it lives (REQ-CMC-005)

Choosing a case in the list SHALL open it on the page of the contribution it
came from, with the case selected.

#### Scenario: Opening a case from the list
- **GIVEN** a dossiq case in "My cases"
- **WHEN** the resident chooses it
- **THEN** the portal opens dossiq's case screen for that case

### Requirement: A cases collection may name the field its status reads as

A `cases` collection MAY declare `statusLabelField`. The normaliser MUST keep it only as a non-empty string naming one of the projected `fields`, or any field when the collection projects none. The case list MUST stamp the field's text on each own and mandated row as `_statusLabel` when it holds text, and MUST leave the raw status on the row. "My cases" MUST show `_statusLabel` when present and the status as before otherwise.

#### Scenario: A Woo request reads "Ontvangen", not a uuid
- GIVEN dossiq's `mijnZaken` declares `statusLabelField: "statusPublicLabel"`
- AND a case has `status: "3c0f5a00-…-b001"` and `statusPublicLabel: "Ontvangen"`
- WHEN the resident opens "Mijn zaken"
- THEN the row shows "Ontvangen"
- AND the row still carries the raw `status`
- @e2e exclude pinned by `PortalCaseListReaderTest::testEachRowCarriesTheStatusWordsTheCollectionNames` and `tests/my-cases-page.spec.mjs`

#### Scenario: A label field the rows never carry is dropped
- GIVEN a `cases` collection with `fields: ["status"]` and `statusLabelField: "statusPublicLabel"`
- WHEN the manifest is normalised
- THEN the collection has no `statusLabelField`
- @e2e exclude pinned by `PortalManifestNormaliserTest::testAStatusLabelFieldIsKeptOnlyWhenItNamesAProjectedField`
