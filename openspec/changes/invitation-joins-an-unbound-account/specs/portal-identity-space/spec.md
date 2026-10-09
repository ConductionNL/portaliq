## ADDED Requirements

### Requirement: An invitation joins a person's own unbound account and gives it the invitation's audience (REQ-PIS-012)

When the session that redeems an invitation's secret (REQ-PIS-008) holds an account of another audience than the waiting account, Portaliq SHALL still join the waiting account into it when all of these hold: the account's identity type is `digid` or `eidas` and it carries an identity reference; its `provisionedBy` is empty; it holds no claims; neither the account nor the waiting account has the `supplier` audience; the session's trust level is `substantial` or higher; and the join's other conditions hold with the waiting account's audience in place of the account's own. The account SHALL take on the waiting account's audience in the same write that adds the claims. In every other case the refusal of another audience in REQ-PIS-008 SHALL stand, and nothing SHALL be spent. A join on a verified or confirmed address alone (REQ-PIS-005, REQ-PIS-006, REQ-PIS-010) SHALL NOT change an audience. When the account's audience moved, the redeem route SHALL reissue the session for the audience read from the account, revoke the old bearer, and answer the new bearer with the audience; the site SHALL store that bearer and read the session again.

#### Scenario: A guardian who signed in before she was invited accepts her invitation
- **GIVEN** a guardian whose first DigiD sign-in made an account with audience `client`, no claims and no `provisionedBy`
- **AND** a `pending` account with audience `parent` and `claims.learniq.guardianRef`, whose invitation was mailed to her
- **WHEN** she follows the link, signs in at trust level substantial, and the site hands the secret back
- **THEN** her own account has audience `parent` and carries `claims.learniq.guardianRef`, the pending account is `void`, the audit trail holds one `portaliq.claim` row, and the answer carries a bearer for the `parent` audience
- @e2e exclude the secret leaves by mail only; covered by PHPUnit `WaitingAccountInvitationTest::testAPersonWhoSignedInBeforeSheWasInvitedTakesOnTheInvitationsAudience`, `PortalAccountClaimControllerTest::testAClaimThatMovedTheAudienceHandsBackABearerForIt`, `PortalSessionServiceTest::testASessionIsReissuedForTheAudienceItsAccountTookOn`, and `tests/claim-invitation.spec.mjs`

#### Scenario: Another identity, organisation or trust level takes on nothing
- **GIVEN** an invitation for a `parent` account
- **WHEN** its secret is handed in by a company identity, a company account, a session below substantial, an account that holds a claim or that an app or clerk provisioned, for an invitation already bound to another person's identity, for an invitation into the `supplier` audience, or for an invitation of another organisation
- **THEN** each answer is the refusal every dead secret gets, the secret is not spent, and the account's audience and claims do not change
- @e2e exclude covered by PHPUnit `WaitingAccountInvitationTest::testOnlyAPersonsOwnUnboundAccountTakesOnAnotherAudience`, `::testAnInvitationOfAnotherOrganisationMovesNoAudience` and `WaitingAccountJoinTest::testEachConditionOfTakingOnAnAudienceRefusesOnItsOwn`

#### Scenario: An address alone never moves an audience
- **GIVEN** an unbound `client` account and a `pending` `parent` account on the same verified address
- **WHEN** the person signs in with that address
- **THEN** nothing joins and her account keeps audience `client`
- @e2e exclude covered by PHPUnit `WaitingAccountJoinTest::testAnAddressAloneNeverMovesAnAudience` and `::testOnlyTheRedeemOfASecretMovesTheAudience`
