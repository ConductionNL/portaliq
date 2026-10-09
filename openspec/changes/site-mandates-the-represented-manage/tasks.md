# Tasks: site-mandates-the-represented-manage

Two PRs to `development`. Each UI scenario gets a Playwright test citing it with `@e2e`.

## Blocking, first

- [ ] **T0** (not run: needs one real eHerkenning broker to say which claim holds the company number; until then a session carries no `kvk`, the party of an eHerkenning sign-in is none and company-held mandates are never read): the KVK number on an eHerkenning session (design D8). Today the claim mapping has one identity claim (`claimMap.identityRef`, default `sub`) and no KVK claim; portaliq reads `identityRef` as the KVK number only when it is 8 digits, and an account per KVK number would merge every employee into one. Add `claimMap.kvk` beside a per-person `identityRef`, carry it on the session as `kvk`, and confirm with one real eHerkenning broker which claim holds the KVK number of the company the person signs in for. REQ-SMR-005 waits on this.
  - PHPUnit `OidcClaimMapperServiceTest::testTheKvkClaimTravelsBesideThePerson`, `::testAMissingKvkClaimGivesNoCompany`

## PR 1: the record and the routes (REQ-SMR-001 to REQ-SMR-006)

- [x] **T1**: typed `onBehalfOf` on write and normalised on read in `PortalMandateService`; `PortalAccessRequestService::grant()` writes `kvk:`.
  - PHPUnit `PortalMandateServiceTest::testAnUntypedKvkNumberReadsAsKvk`, `PortalAccessRequestServiceTest::testAGrantWritesATypedParty`
- [ ] **T2**: (partial: `portalMandate` and `portalInvitation` carry the new fields, and `PortalMandateAdminService::accept()` writes the mandate once with the invitation's terms, refusing the inviter; the guardian/staff `PortalInvitationService::accept()` is untouched: a mandate invitation is its own kind) `portalMandate` gains `revokedBy`, `revokedAt`, `invitationId`; `portalInvitation` gains the mandate terms; `PortalInvitationService::accept()` writes the mandate once.
  - PHPUnit `PortalInvitationServiceTest::testAcceptingWritesTheMandateWithTheTerms`, `::testTheInviterCannotAccept`
- [x] **T2b** (`holder`, the session's parties and `mandatesFor(holders:)` are done; the claim that fills `kvk` is T0): `portalMandate.holder`; `accept()` writes `kvk:` for an eHerkenning session with a KVK number, else `subject:`; `PortalMandateService::mandatesFor()` matches the session's parties; its callers pass the session (design D7, REQ-SMR-005). The states of REQ-SMR-006, final once revoked or expired.
  - PHPUnit `PortalMandateServiceTest::testACompanyMandateReachesEverySignInForThatKvk`, `::testAMandateWithoutHolderIsHeldByItsSubject`, `MandateControllerTest::testAnExpiredMandateCannotBeExtended`, `PortalInvitationServiceTest::testARevokedInvitationCannotBeAccepted`
- [x] **T3** (`MandateController`, routes under `/portal/api/mandates`, `PortalMandateAdminServiceTest`; "acting under a mandate" is read from `actingUnderMandate` on the session, which nothing sets yet: the acting-for choice is a request parameter today): `PortalMandateAdminService` and the routes of design D5, party from the session only.
  - PHPUnit `MandateControllerTest::testAnotherPartysMandateIs404`, `::testActingUnderAMandateCannotManage`, `::testAPastEndDateIsRefused`; route-auth and IDOR gates green

## PR 2: the screens

- [ ] **T4**: (not run: the screens need the routes on a live instance, and Playwright): "Machtigingen" page, "Iemand machtigen" dialog, "Uw machtiging" with "Machtiging stoppen"; the acting-for choice falls back after a revoke.
  - e2e: invite, accept as a second account, revoke

- [ ] **T5**: (not run: the openspec CLI): `openspec validate site-mandates-the-represented-manage --strict`
