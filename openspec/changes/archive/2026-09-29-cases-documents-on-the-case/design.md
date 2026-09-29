# Design: cases-documents-on-the-case

Read at portaliq development `eeda3fa`.

## What is there today

- `appinfo/routes.php:378-384` registers the citizen case routes: `citizenCase#show` (GET), `#amend` (PATCH), `#addDocument` (POST `.../documents`) and `#withdraw` (POST `.../withdraw`), all under `/portal/api/citizen/cases/{register}/{schema}/{id}`.
- `lib/Controller/CitizenCaseController.php:140` `show()` proves the case is the subject's through `context()` (line 438), then returns `case`, `writableSet`, `withdrawal` and `documents`. Line 153 fills `documents` from `PortalFileReader::listFiles()`, which lists every file in the case object's OpenRegister folder (`lib/Service/PortalFileReader.php:100`) as `{id, name, size}`.
- `CitizenCaseController.php:224` `addDocument()` writes the resident's upload into that same folder through `PortalFileWriter::attachFile()` (`lib/Service/PortalFileWriter.php:92`), which calls OpenRegister's `FileService::addFile()` at line 117 without tags. OpenRegister's `addFile()` accepts a `tags` array (openregister `lib/Service/FileService.php:1489`).
- `src/portal/components/CitizenCase.jsx:191-211` renders the list as plain `<li>{file.name}</li>` with no link.
- The generic download is `contribution#downloadFile` (`routes.php:315`, `ContributionController.php:782`). It re-reads the object through the scoped reader, needs `filesDownload: true` on the collection and `minTrust` satisfied, streams through `PortalFileReader::streamFile()` (line 152), and audits with the `download` verb (`lib/Service/AuditTrailService.php:157`). `PageView.jsx:89-127` `FileList` is its only caller.
- The timeline pattern: a collection declares `timeline: {label, provider}`, `lib/Contribution/TimelineProviderMethod.php` keeps it only when `provider` is a plain identifier that is not one of the contract's own methods, and `PortalTimelineReader::entries()` (`lib/Service/PortalTimelineReader.php:71`) calls `$provider->{$method}($id)` after the caller proved the object is the subject's.

## D1. The case app decides what is published, through a declared method

A case collection may declare `documents: {label, provider}`. `provider` names a public method on the app's own portal provider, validated exactly like `timeline.provider`: a plain identifier, not a contract method. A malformed declaration drops the key.

The method is called with the case id, after `context()` proved the case is the resident's. It returns a list of entries:

- `id`: the app's own stable id for the document.
- `title`: what the resident reads.
- `kind`: `decision` or `document`.
- `date`: the date the resident should see (decision date, sent date).
- `file`: `{register, schema, id, fileId}`, where the bytes live in OpenRegister.
- Optional `mimeType` and `size`.

This is the timeline's rule applied to documents: the app decides what is public, and portaliq hands it on. The alternative was a portal-side filter on status and confidentiality, as NL Portal does. That would put a statutory decision about each case type's documents in a portal setting, and portaliq cannot see a case app's document schema anyway.

Portaliq drops an entry without a well-formed `id`, `title` or `file`, and logs the provider. It adds and reorders nothing else, apart from D3.

## D2. The browser never sees where a file lives

`show()` returns each entry as `{id, title, kind, date, mimeType, size}`. The `file` reference stays on the server.

A new route `citizenCase#document`, `GET /portal/api/citizen/cases/{register}/{schema}/{id}/documents/{documentId}`, `#[PublicPage]`, `#[NoCSRFRequired]`, `#[AnonRateLimit(limit: 60, period: 60)]`, answers a download:

1. `context()` proves the case is the resident's. A foreign case gets the same 404 as a missing one.
2. The provider is called again for this case, and the entry with `documentId` is looked up in its answer. An id the provider did not return gets the same 404.
3. The entry's `file` is streamed through `PortalFileReader::streamFile()`, which resolves the file strictly inside that object's folder.
4. The download is audited with the `download` verb.

Nothing in the request names a register, schema or file id to stream. A tampered `documentId` can only pick among the documents the app already published for this case.

## D3. A decision is shown first

Entries with `kind` `decision` are listed first under the heading "Decision" (Dutch: "Besluit"), newest first, with their date. Other entries follow under "Documents" (Dutch: "Documenten"), in the order the app returned them.

## D4. The resident's own uploads are tagged and listed apart

`PortalFileWriter::attachFile()` passes the tag `portal:from-applicant` to `addFile()` for an upload made through `addDocument()`. `show()` lists the case folder's files carrying that tag as entries with `kind` `yours`, shown under "Sent by you" (Dutch: "Door u gestuurd"). Their download id is `upload:<fileId>`, and the download route streams it from the case folder only when the file still carries the tag.

Files in the case folder without that tag are not listed. That closes the over-exposure `show()` has today.

## D5. No documents method means only your own uploads

A case collection without `documents` gets the resident's tagged uploads and nothing else. The empty state reads: "There are no documents on this case yet." (Dutch: "Er staan nog geen documenten bij deze zaak.")

## D6. The screen

`CitizenCase.jsx` renders each entry as a button that calls a new `portalApi.downloadCitizenDocument(collection, caseId, entry)`. It follows the existing `downloadFile()` shape (`src/portal/lib/portalApi.js:580`): a blob fetch with the bearer, then a save. A failed download shows "The document could not be opened. Try again later." (Dutch: "Het document kon niet worden geopend. Probeer het later opnieuw.")

## Risks

- **Older uploads drop out of the resident's view.** Files a resident added before this change carry no tag. The audit trail records the write but not the file name (`CitizenWriteRecorder::announce()`, `lib/Service/CitizenWriteRecorder.php:188`), so portaliq cannot tag them afterwards. They stay on the case, where the handler sees them.
- **The provider is called twice for a download.** Once to list and once to stream. A slow provider is felt twice; the second call is what keeps a stale list from granting a download.
- **Until dossiq ships its method, dossiq cases show only uploads.** That is less than the unfiltered folder shows today. It is the fail-closed direction, and the sibling half restores the rest.

## What this change does not do

- It does not change `contribution#downloadFile`, `filesDownload` or `PageView.jsx`'s `FileList`.
- It does not store a copy of any document in portaliq.
- It does not decide a publication rule for any case type.
