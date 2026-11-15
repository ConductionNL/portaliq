---
status: proposed
---

# Spec: portal-notifications-and-preferences

## Purpose

A notice another app writes into the portal inbox reaches the resident by
email too. Contract C3 in hydra
`openspec/changes/woo-citizen-journey/design.md`.

## ADDED Requirements

### Requirement: Another app's notice with a declared rule key MUST be sent by email (REQ-WJE-006)

A `portalMessage` MAY carry `ruleKey`. When a `portalMessage` is created outside
portaliq's own writes with a `ruleKey`, portaliq SHALL dispatch that rule key
for the app named before its first dot, for the message's subject. The email
SHALL go only when that app's contribution declares the rule key and the
resident allows email. The inbox entry SHALL stand in every case. No Berichtenbox send SHALL be
queued for it (hydra #730: not in this journey). This holds
for `pipelinq.question.answered`, `dossiq.wooRequest.published` and
`opencatalogi.savedSearch.matched`. Implements hydra `woo-citizen-journey`
"Every answer, decision and alert MUST reach the resident through portaliq's
notice path".

#### Scenario: An answer to a question
- **GIVEN** pipelinq declares `pipelinq.question.answered` and a resident with email on
- **WHEN** pipelinq writes a `portalMessage` for them with that rule key
- **THEN** the message is in their inbox and an email is queued for `pipelinq.question.answered`
- test: PHPUnit `tests/Unit/Listener/PortalRecordChangeListenerTest.php` ("foreign message with a declared key")

#### Scenario: A message borrowing another app's key
- **GIVEN** opencatalogi does not declare `pipelinq.question.answered`
- **WHEN** a `portalMessage` with that key is written
- **THEN** the dispatch is asked for app `pipelinq` only, so opencatalogi's contribution never sends it
- test: PHPUnit `tests/Unit/Listener/PortalRecordChangeListenerTest.php` ("app from the rule key")

#### Scenario: portaliq's own message
- **GIVEN** portaliq writes a change notice itself
- **WHEN** the created event arrives
- **THEN** nothing is dispatched a second time
- test: PHPUnit `tests/Unit/Listener/PortalRecordChangeListenerTest.php` ("own message not dispatched twice")
