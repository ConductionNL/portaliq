---
status: proposed
---

# Spec: portal-contribution-contract

## Purpose

An app offers an action on another app's collection. Journeys J4.1 and J5.1, contract C7 in
hydra `openspec/changes/woo-citizen-journey/design.md`.

## ADDED Requirements

### Requirement: An endpoint action MUST be able to attach to another app's collection (REQ-WJE-004)

An endpoint action that declares `attachTo: { app, schema }` and a valid
`rowField` SHALL be listed in `attachedActions` on every collection of `app`
whose `schema` equals `attachTo.schema`. The portal SHALL show it on that
collection's detail and ask its declared fields. The forward SHALL prove the
row through the TARGET collection's scope, SHALL require the action to be
still offered in its own app's contribution, and SHALL send it to its own app
with `rowField` set to the row id. A malformed `attachTo` SHALL attach nothing
and SHALL leave the action as it was. Implements hydra `woo-citizen-journey`
"A question about a dossier MUST carry a snapshot of the dossier, not access
to it" and "A Woo request MUST be created by one dossiq path, from the portal
and from pipelinq alike" (the portal half of each).

#### Scenario: A resident asks a question about their dossier
- **GIVEN** pipelinq offers `askAboutDossier` with `attachTo: { app: "opencatalogi", schema: "collection" }`, `rowField: "collectionId"` and field `question`
- **WHEN** a resident opens their dossier and sends "Wanneer wordt dit besloten?" through "Stel een vraag over dit dossier"
- **THEN** pipelinq's endpoint receives `{ question: "Wanneer wordt dit besloten?", collectionId: <dossier id> }` with the resident's subject assertion
- test: PHPUnit `tests/Unit/Controller/PortalRowActionControllerTest.php` ("attached action forwards with the proven row")

#### Scenario: A resident tries another resident's dossier
- **GIVEN** a dossier owned by someone else
- **WHEN** a resident forwards `askAboutDossier` on its id
- **THEN** the answer is 404 and nothing is forwarded
- test: PHPUnit `tests/Unit/Controller/PortalRowActionControllerTest.php` ("attached action on a foreign row")

#### Scenario: An app that is gone
- **GIVEN** dossiq is disabled
- **WHEN** a resident opens their dossier
- **THEN** "Start een Woo-verzoek" does not show
- test: PHPUnit `tests/Unit/Contribution/AttachedActionResolverTest.php` ("no declaring app, no attachment")
