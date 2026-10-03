# Tasks: site-mandates-the-represented-manage

Two PRs to `development`. Each UI scenario gets a Playwright test citing it with `@e2e`.

## PR 1: the record and the routes (REQ-SMR-001 to REQ-SMR-004)

- [ ] **T1**: typed `onBehalfOf` on write and normalised on read in `PortalMandateService`; `PortalAccessRequestService::grant()` writes `kvk:`.
  - PHPUnit `PortalMandateServiceTest::testAnUntypedKvkNumberReadsAsKvk`, `PortalAccessRequestServiceTest::testAGrantWritesATypedParty`
- [ ] **T2**: `portalMandate` gains `revokedBy`, `revokedAt`, `invitationId`; `portalInvitation` gains the mandate terms; `PortalInvitationService::accept()` writes the mandate once.
  - PHPUnit `PortalInvitationServiceTest::testAcceptingWritesTheMandateWithTheTerms`, `::testTheInviterCannotAccept`
- [ ] **T3**: `PortalMandateAdminService` and the routes of design D5, party from the session only.
  - PHPUnit `MandateControllerTest::testAnotherPartysMandateIs404`, `::testActingUnderAMandateCannotManage`, `::testAPastEndDateIsRefused`; route-auth and IDOR gates green

## PR 2: the screens

- [ ] **T4**: "Machtigingen" page, "Iemand machtigen" dialog, "Uw machtiging" with "Machtiging stoppen"; the acting-for choice falls back after a revoke.
  - e2e: invite, accept as a second account, revoke

- [ ] **T5**: `openspec validate site-mandates-the-represented-manage --strict`
