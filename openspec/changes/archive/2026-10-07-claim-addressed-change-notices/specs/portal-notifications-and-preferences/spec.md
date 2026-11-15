---
status: proposed
---

# Spec: portal-notifications-and-preferences

## Purpose

A change rule can reach residents whose app reference, not whose portal
reference, the record holds, and can say in its own words what happened.

## MODIFIED Requirements

### Requirement: A case app declares which change a resident hears about (REQ-NAP-001)

A contribution's `notifications` list SHALL accept, next to plain rule keys, a rule object naming a `ruleKey`, one of the contribution's own `collection`s and an `on` condition with a `field` that the collection projects and the operator `changed`. Portaliq SHALL drop a rule whose collection is scoped through `scopeClaim` or `via` unless the rule names its `recipients` (REQ-NAP-012), SHALL drop a rule whose field is not projected, and SHALL log it.

#### Scenario: A well-formed rule is kept
- **GIVEN** a case app declaring a rule on its cases collection for the `status` field
- **WHEN** portaliq aggregates the resident's contributions
- **THEN** the rule is kept and the plain rule keys beside it still work
- @e2e exclude Manifest normalisation; pinned by NotificationRuleNormaliserTest::testKeepsAWellFormedRule and ::testPlainStringsStillPass

#### Scenario: A rule on an unprojected field is dropped
- **GIVEN** a rule naming a field the collection does not project to residents
- **WHEN** portaliq aggregates the contributions
- **THEN** the rule is dropped and a warning names the app and the rule
- @e2e exclude Manifest normalisation; pinned by NotificationRuleNormaliserTest::testDropsAnUnprojectedField

#### Scenario: A rule on a via collection without recipients is dropped
- **GIVEN** a rule on a collection read through `via` or `scopeClaim` that names no recipients
- **WHEN** portaliq aggregates the contributions
- **THEN** the rule is dropped, and the listener does not act on it even when handed it unnormalised
- @e2e exclude Manifest normalisation; pinned by NotificationRuleNormaliserTest::testDropsARuleOnAViaCollection and ClaimAddressedChangeNoticeTest::testWithoutRecipientsAViaRuleStaysSilent

#### Scenario: A plain key nothing fires is dropped
- **GIVEN** a supplier app declaring `notifications: ["tenderPublished", "message.created", "dossiq.invoiceDue"]` as app `dossiq`
- **WHEN** portaliq aggregates the contributions
- **THEN** `message.created` and `dossiq.invoiceDue` are kept, `tenderPublished` is dropped, and a warning names the app and says how to declare the key
- @e2e exclude Manifest normalisation; pinned by NotificationRuleNormaliserTest::testDropsABareKeyNothingFires, ::testKeepsKeysSomethingFires and ::testLogsADroppedKeyWithTheApp

#### Scenario: Another app's key is dropped
- **GIVEN** app `opencatalogi` declaring `pipelinq.question.answered`
- **WHEN** portaliq aggregates the contributions
- **THEN** the key is dropped: a portalMessage carrying it is dispatched for `pipelinq` only, so it never fires for `opencatalogi`
- @e2e exclude Manifest normalisation; pinned by NotificationRuleNormaliserTest::testDropsAnotherAppsKeyAndTheKeyOfADroppedRule

## ADDED Requirements

### Requirement: A change rule may reach residents by a claim (REQ-NAP-012)

A change rule MAY declare `recipients` with a `field` of the record and a `claim` of the contributing app, as a bare name or as `<app>.<name>` with the contributing app's own id. Portaliq SHALL drop a rule whose claim names another app or whose recipients are malformed. When the rule's field changes, portaliq SHALL find the active portal accounts whose `claims.<app>.<claim>` equals the record's value at `field`, SHALL read the record as each of them through the collection's own scoped read (its `scopeField`, `scopeClaim`, `via`, `filter` and `fields`), and SHALL write the inbox message and dispatch the rule's key only for an account that may read the record. A record without a value at `field` SHALL reach nobody. Delivery SHALL use the same inbox message and dispatch as REQ-NAP-002, so the resident's channel preferences apply unchanged.

#### Scenario: The guardian who booked hears that the teacher acknowledged
- **GIVEN** a booking whose `guardianRef` is the value of Fatima's claim `claims.learniq.guardianRef`, and a rule on the bookings collection with `recipients` `{"field": "guardianRef", "claim": "guardianRef"}`
- **WHEN** the teacher moves the booking from `booked` to `acknowledged`
- **THEN** Fatima's inbox holds one new message about the booking, and the rule's key is dispatched for her account only
- **AND** an account holding the same value under another app's claim, and a withdrawn account, are not told
- @e2e exclude Needs a teacher action in learniq and a guardian portal session on one instance; pinned by ClaimAddressedChangeNoticeTest::testTheGuardianWhoseClaimTheRecordHoldsIsTold with OpenRegister's real events, and checked live on the primary-school instance (learniq claim-addressed booking notice)

#### Scenario: Another family's guardian is not told
- **GIVEN** an account holding the claim value on the record that the collection's scoped read refuses
- **WHEN** the booking changes
- **THEN** no message is written and nothing is dispatched
- @e2e exclude Absence of a message to a second family needs two guardian sessions; pinned by ClaimAddressedChangeNoticeTest::testAnAccountThatMayNotReadTheRecordIsNotTold

#### Scenario: No claim value, no message
- **GIVEN** a booking without a `guardianRef`
- **WHEN** it changes
- **THEN** no account is looked for, no message is written and nothing is dispatched
- @e2e exclude pinned by ClaimAddressedChangeNoticeTest::testNoClaimValueNoMessage

### Requirement: A change rule may say in its own words what happened (REQ-NAP-013)

A change rule MAY declare `messages`, a map from a new value of its field to a `subject` and a `body`. Each text SHALL be a non-empty string or a map of language code to string, and a `{field}` or `{field|datetime}` placeholder SHALL name a field the collection projects; portaliq SHALL drop a rule that breaks this. The message SHALL be written in the portal's language, else English, else the first text given, with placeholders filled from the record as the resident may read it; `{field|datetime}` SHALL print `d-m-Y H:i`. A new value without an entry SHALL not be reported. A rule without `messages` SHALL keep the generic text of REQ-NAP-002.

#### Scenario: The acknowledgement names the time and the teacher
- **GIVEN** the bookings rule with a Dutch message for `acknowledged` reading "De leerkracht heeft uw gesprekstijd bevestigd: {startsAt|datetime}, met {teacherName}."
- **WHEN** the teacher acknowledges the booking for 13 October 2026 at 18:00 with J. de Vries, on a portal whose language is Dutch
- **THEN** the message body reads "De leerkracht heeft uw gesprekstijd bevestigd: 13-10-2026 18:00, met J. de Vries."
- @e2e exclude pinned by ClaimAddressedChangeNoticeTest::testTheGuardianWhoseClaimTheRecordHoldsIsTold

#### Scenario: A decline carries the teacher's note
- **GIVEN** a message for `declined` with `{declineNote}`
- **WHEN** the teacher declines with the note "Ik ben ziek"
- **THEN** the message body contains "Ik ben ziek"
- @e2e exclude pinned by ClaimAddressedChangeNoticeTest::testADeclineCarriesTheTeachersNote

#### Scenario: A value without words is not reported
- **GIVEN** the same rule, with no message for `cancelled`
- **WHEN** the booking moves to `cancelled`
- **THEN** no message is written and nothing is dispatched
- @e2e exclude pinned by ClaimAddressedChangeNoticeTest::testAValueWithoutAMessageIsNotReported

#### Scenario: A placeholder on an unprojected field drops the rule
- **GIVEN** a message whose body names a field the collection does not project
- **WHEN** portaliq aggregates the contributions
- **THEN** the rule is dropped and the warning names the field
- @e2e exclude Manifest normalisation; pinned by NotificationRuleNormaliserTest::testDropsAForeignClaimAMalformedRecipientAndAnUnprojectedPlaceholder
