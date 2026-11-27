# Proposal: document-download-terms

## Why

filinq releases a decision or document to the requester of a record, and a released document can carry download conditions (a download agreement: "do not republish", with a version). filinq serves the file only after the person accepted the version in force; its `opened` hook already refuses the download otherwise (filinq `specify-capability-rows` task 3.3). On the portal the resident had no way to read those conditions or accept them, so the download simply failed with "The document could not be opened".

## What Changes

- A collection's `documents` declaration MAY name a `terms` hook and an `accept` hook next to `opened`, each a plain method name on the contributing app's provider, called in-process with the proven object id, the listed document id and the session's subject (the same four fields `opened` gets). The normaliser keeps them only as plain method names.
- `GET /portal/api/collections/{register}/{schema}/{id}/documents/{documentId}/terms` answers the app's conditions (`mayDownload`, `message`, `agreement {version, text}`) for a listed document of the subject's own object, or `terms: null`.
- `POST` on the same path with `{version}` records the subject's acceptance through the `accept` hook; 409 with a sentence when it is not recorded.
- The documents block reads the terms before a download from a collection that declares `terms`; when the subject has not accepted them it shows the conditions with "Akkoord en downloaden" and "Annuleren", and downloads only after the acceptance is recorded.

## Impact

- `lib/Contribution/DocumentsProviderMethod.php`, `lib/Service/CollectionDocuments.php`, `lib/Kernel/Contracts/CollectionDocumentsInterface.php`, `lib/Controller/PortalCollectionDocumentsController.php`, `appinfo/routes.php`
- `src/shared/documentTermsApi.js` (over the api's `request` door), `src/site/components/mijn/DocumentsBlock.vue`, `src/site/components/mijn/strings.js`
- The acceptance is keyed by the app to the session's subject; portaliq never takes the subject from the request.
