## ADDED Requirements

### Requirement: An invitation joins a person's own unbound account and gives it the invitation's audience (REQ-PIS-012)

When the session that redeems an invitation's mailed link (REQ-PIS-008) holds an account of another audience than the waiting account, Portaliq SHALL still join the waiting account into it when all of these hold: the waiting account's audience is on the organisation's allow-list (`unboundAudiences` in its presentation override, else `parent`; never `supplier`); the account's identity type is `digid` or `eidas` and it carries an identity reference; its `provisionedBy` is empty; it holds no claims; its audience is not `supplier`; the session's trust level is `substantial` or higher; and the join's other conditions hold with the waiting account's audience in place of the account's own. A code from a paper letter (REQ-PIS-009) SHALL NOT move an audience. The account SHALL take on the waiting account's audience in the same write that adds the claims. In every other case the refusal of another audience in REQ-PIS-008 SHALL stand, and nothing SHALL be spent. A join on a verified or confirmed address alone (REQ-PIS-005, REQ-PIS-006, REQ-PIS-010) SHALL NOT change an audience. When the account's audience moved, Portaliq SHALL record an `audience` row in the audit trail with the old and the new audience, and SHALL mail the waiting account's address that its invitation was accepted, with the date and who to contact; that mail SHALL carry no secret and no link. The redeem route SHALL then reissue the session for the audience read from the account, with that audience's role only, record the rotation before it revokes the old bearer, and answer the new bearer with the audience; the site SHALL store that bearer and read the session again.

#### Scenario: A guardian who signed in before she was invited accepts her invitation
- **GIVEN** a guardian whose first DigiD sign-in made an account with audience `client`, no claims and no `provisionedBy`
- **AND** a `pending` account with audience `parent` and `claims.learniq.guardianRef`, whose invitation was mailed to her
- **WHEN** she follows the link, signs in at trust level substantial, and the site hands the secret back
- **THEN** her own account has audience `parent` and carries `claims.learniq.guardianRef`, the pending account is `void`, the audit trail holds a `portaliq.audience` row (from `client` to `parent`) and a `portaliq.claim` row, the invited address gets a mail that the invitation was accepted, and the answer carries a bearer for the `parent` audience with the role `parent:read`
- @e2e exclude the secret leaves by mail only; covered by PHPUnit `WaitingAccountInvitationTest::testAPersonWhoSignedInBeforeSheWasInvitedTakesOnTheInvitationsAudience`, `PortalAccountClaimControllerTest::testAClaimThatMovedTheAudienceHandsBackABearerForIt`, `PortalSessionServiceTest::testASessionIsReissuedForTheAudienceItsAccountTookOn`, and `tests/claim-invitation.spec.mjs`

#### Scenario: Another identity, organisation or trust level takes on nothing
- **GIVEN** an invitation for a `parent` account
- **WHEN** its secret is handed in by a company identity, a company account, a session below substantial, an account that holds a claim or that an app or clerk provisioned, for an invitation already bound to another person's identity, for an invitation into the `supplier` audience, or for an invitation of another organisation
- **THEN** each answer is the refusal every dead secret gets, the secret is not spent, and the account's audience and claims do not change
- @e2e exclude covered by PHPUnit `WaitingAccountInvitationTest::testOnlyAPersonsOwnUnboundAccountTakesOnAnotherAudience`, `::testAnInvitationOfAnotherOrganisationMovesNoAudience` and `WaitingAccountJoinTest::testEachConditionOfTakingOnAnAudienceRefusesOnItsOwn`

#### Scenario: A code from a paper letter never moves an audience
- **GIVEN** an unbound `client` account and a `pending` `parent` account with a code for a letter
- **WHEN** the code is typed on "My account"
- **THEN** the answer is the refusal every dead secret gets, the code is not spent, and no notice is mailed
- @e2e exclude covered by PHPUnit `WaitingAccountInvitationTest::testACodeFromALetterNeverMovesAnAudience`

#### Scenario: The organisation decides which audiences an unbound account may take on
- **GIVEN** an organisation without `unboundAudiences`, and one that sets it to `participant`
- **WHEN** an unbound account redeems a link for a `participant` invitation and for a `parent` invitation
- **THEN** the first organisation lets only `parent` through, the second only `participant`, and `supplier` is never let through
- @e2e exclude covered by PHPUnit `WaitingAccountInvitationTest::testTheAudiencesAnUnboundAccountMayTakeOnAreTheOrganisations`

#### Scenario: The invited address hears that its invitation was accepted
- **GIVEN** an audience move on 9 October 2026
- **WHEN** the claim is done
- **THEN** the invited address gets a mail saying the invitation to the portal was accepted on 9 October 2026, and who to contact if that was not them; the mail has no link
- @e2e exclude mail only; covered by PHPUnit `PortalIdentityMailerTest::testTheInvitedAddressIsToldItsInvitationWasAccepted`

#### Scenario: An address alone never moves an audience
- **GIVEN** an unbound `client` account and a `pending` `parent` account on the same verified address
- **WHEN** the person signs in with that address
- **THEN** nothing joins and her account keeps audience `client`
- @e2e exclude covered by PHPUnit `WaitingAccountJoinTest::testAnAddressAloneNeverMovesAnAudience` and `::testOnlyTheRedeemOfASecretMovesTheAudience`
