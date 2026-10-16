## ADDED Requirements

### Requirement: An app may provision an active account for a Nextcloud user

An app MAY dispatch `PortalAccountProvisionRequestedEvent` with `nextcloudUid` and `portal` to
provision an active portal account for the `nextcloud` sign-in mode. Portaliq MUST create it only
when the app id is known, the Nextcloud user exists, and `portal` is a published portal of the
given organisation that offers the `nextcloud` mode. The account MUST be `active`, its `subjectRef`
MUST be the user id, `provisionedBy` MUST be the dispatching app, and a given e-mail address MUST
be stored as unverified. A repeat for the same user, organisation and audience MUST answer the
existing account and write nothing. Portaliq MUST NOT change an existing account: an account under
that subjectRef in another organisation or audience, or a live account in the organisation holding
the same address under another subjectRef, MUST be refused as `conflict`; an account under that
subjectRef that is not active MUST be refused as `not_active`. No route MUST reach this path.

#### Scenario: A training participant gets an account
@e2e exclude PHPUnit through the real event and listener: tests/Unit/Service/Identity/NextcloudAccountProvisionerTest.php
- GIVEN the published portal `warmtepompacademie` of organisation `academie`, offering the `nextcloud` mode, and the Nextcloud user `training-deelnemer-151`
- WHEN learniq dispatches the event with that user id, the portal, audience `participant` and organisation `academie`
- THEN an active account with subjectRef `training-deelnemer-151` exists, and the event answers that subjectRef with status `active`

#### Scenario: The install runs again
@e2e exclude PHPUnit: tests/Unit/Service/Identity/NextcloudAccountProvisionerTest.php
- GIVEN that account exists
- WHEN the same event is dispatched again
- THEN nothing is written and the same subjectRef is answered

#### Scenario: Somebody else's account
@e2e exclude PHPUnit: tests/Unit/Service/Identity/NextcloudAccountProvisionerTest.php
- GIVEN an account with subjectRef `training-deelnemer-151` in organisation `gemeente-x`, or a waiting account in `academie` for the same address under a minted subjectRef
- WHEN the event is dispatched
- THEN it is refused as `conflict` and no account changes

#### Scenario: A portal without the mode
@e2e exclude PHPUnit: tests/Unit/Service/Identity/NextcloudAccountProvisionerTest.php
- GIVEN a portal that offers only `digid`
- WHEN the event names it
- THEN it is refused as `mode_not_offered`
