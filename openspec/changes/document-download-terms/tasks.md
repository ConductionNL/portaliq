# Tasks: document-download-terms

- [x] 1. Hooks: keep `terms` and `accept` on a `documents` declaration as plain method names (`DocumentsProviderMethod`); `CollectionDocuments::terms()` and `::accept()` call them only for a listed document, with the session's subject, failing closed (a throw, no hook, no version, anything but an array or exactly true). — PHPUnit `CollectionDocumentsTest`, `DocumentsProviderMethodTest::testTheTermsAndAcceptHooksAreKeptOrDroppedAlone`.
- [x] 2. Routes: `GET` and `POST …/documents/{documentId}/terms`, proven like a paper (no session 401, outside the scope 404), the POST answering 409 with a sentence when the acceptance is not recorded. — PHPUnit `PortalCollectionDocumentsControllerTest`.
- [x] 3. Documents block: read the terms before a download when the collection declares `terms`, show them with "Akkoord en downloaden" and "Annuleren", download only after a recorded acceptance; en and nl strings. — `node --test tests/mijn-documents.spec.mjs`.
- [ ] 4. Live: a filinq released document with a download agreement is accepted and downloaded on a running portal. — not run: needs a live instance with filinq and portaliq.
