---
status: proposed
---

# Spec: portal-contribution-contract

## Purpose

A resident sees what is in their dossier, notices what is no longer public,
removes an item, and gets the share link. Journeys J3.2, J3.4 and J3.5 in hydra
`openspec/changes/woo-citizen-journey/journey-map.md`.

## ADDED Requirements

### Requirement: A collection MUST be able to declare an item list read from its app (REQ-MYD-001)

A collection MAY declare `itemList: { label?, provider, removeAction? }`. The
portal SHALL call `provider` with the object id only after the resident's own
scoped read of that object succeeded, and SHALL answer 404 without calling it
otherwise. It SHALL pass on per item only `id`, `title`, `url`, `note`,
`public` and `addedAt`, and SHALL drop a `url` that is not https or an
instance-local path. Implements hydra `woo-citizen-journey` "A resident's
dossier MUST be owned by the resident and readable by nobody else unless
shared".

#### Scenario: A resident opens their own dossier
- **GIVEN** a signed-in resident who owns a dossier with two items, each with a note
- **WHEN** they open it on the portal
- **THEN** they see both items with their titles, links and notes
- test: PHPUnit `tests/Unit/Controller/PortalItemListControllerTest.php` ("own object, items returned")

#### Scenario: A resident guesses another resident's dossier id
- **GIVEN** a dossier owned by someone else
- **WHEN** a resident asks for its items
- **THEN** the answer is 404 and the provider method is not called
- test: PHPUnit `tests/Unit/Controller/PortalItemListControllerTest.php` ("foreign object, provider not called")

#### Scenario: An unsafe link in an item
- **GIVEN** an item whose `url` is `javascript:alert(1)`
- **WHEN** the items are read
- **THEN** that item has no `url`
- test: PHPUnit `tests/Unit/Service/PortalItemReaderTest.php` ("unsafe url dropped")

### Requirement: An item that is no longer public MUST say so (REQ-MYD-002)

An item with `public: false` SHALL stay in the owner's list, marked "Niet meer
openbaar". Implements hydra `woo-citizen-journey` "A shared dossier MUST show
only what is public at the moment it is read" (the owner's view).

#### Scenario: A depublished item in the owner's view
- **GIVEN** a dossier item whose publication was depublished yesterday
- **WHEN** the owner opens the dossier
- **THEN** the item shows with "Niet meer openbaar"
- test: `tests/my-dossiers.spec.mjs` ("not public marker")

### Requirement: A resident MUST be able to remove one item (REQ-MYD-003)

When `itemList.removeAction` names an endpoint row action of the same
contribution with the field `itemId`, each item SHALL offer "Verwijderen", which
forwards `{ itemId }` with the proven dossier id. A `removeAction` without
`itemId` in its fields SHALL be dropped.

#### Scenario: A resident removes an item
- **GIVEN** a dossier with two items and a `removeAction` `removeCollectionItem`
- **WHEN** the resident removes the first item
- **THEN** `removeCollectionItem` is forwarded with `{ itemId: <first item id> }` and the dossier id
- test: `tests/my-dossiers.spec.mjs` ("remove body")

#### Scenario: A remove action that cannot say which item
- **GIVEN** a `removeAction` whose fields lack `itemId`
- **WHEN** the manifest is normalised
- **THEN** `removeAction` is dropped and the list still renders
- test: PHPUnit `tests/Unit/Contribution/ItemListConfigNormaliserTest.php` ("remove action without itemId")

### Requirement: A link in an action's answer MUST be shown to the resident (REQ-MYD-004)

When an endpoint row action succeeds and its answer carries `link` that is
https or instance-local, the confirm step SHALL show that link in a read-only
field with a copy button.

#### Scenario: A resident shares a dossier
- **GIVEN** a dossier and a share action that answers `{ link: "https://gemeente.nl/…/shared/abc" }`
- **WHEN** the resident runs it
- **THEN** the link shows in a read-only field with "Kopieer link"
- test: `tests/my-dossiers.spec.mjs` ("answer link")
