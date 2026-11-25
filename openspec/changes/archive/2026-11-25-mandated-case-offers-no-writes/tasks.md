# Tasks: mandated-case-offers-no-writes

- [x] **T1**: `readUnderMandate()` and `withoutRecordWrites()`; `ContributionPage.visibleBlocks` applies them to a record read under a mandate
  - `tests/collection-loader.spec.mjs` "a record read under a mandate is read-only", "the case page under a mandate drops its writes and keeps what it reads"
- [x] **T2**: Live check on the Zuiddrecht rehearsal: Linda opens Henk's case and is offered no upload, message, bezwaar or klacht
  - Done 25 Nov 2026 on a sandbox Nextcloud 34.0.6 with openregister, dossiq, opencatalogi and portaliq development and the Zuiddrecht example set (its objects moved into the organisation zuiddrecht, see COORDINATOR-QUEUE 25 Nov): Linda signs in with the demo card, "Mijn zaken" lists her case and four of Henk's under "Henk Bakker". Her own "Parkeervergunningen binnenstad" offers a file field, "Bericht sturen" and "Aanvraag intrekken"; Henk's "Kapvergunning Molenweg 4" shows its steps and offers no control at all. Writing to it through the API as Linda, with `actingFor=henk.bakker` and the mandate id, answers 403 `case-not-yours`.
