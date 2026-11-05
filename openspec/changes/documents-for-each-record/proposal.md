# Proposal: documents-for-each-record

## Why

[wilgenboom/Documenten](https://identity.conduction.nl/screens/board?id=wilgenboom/Documenten) shows one page with a group per child ("Vera", "Groep 7", her reports) and a group "Van school" ("Voor alle ouders"), each row with "Nieuw" and "Downloaden". MijnMenu puts the page under "Van school" as "Rapporten en documenten".

The site already serves the documents of one object, after it proved that object is the subject's (`documents` on a collection, `PortalCollectionDocumentsController`). Three things stop the board's page:

- A documents block only lives on a record page, which shows one open record. A guardian with two children cannot see both children's reports on one page.
- The entry reader keeps `id`, `title`, `kind`, `date`, `file`, `mimeType` and `size` only, so the provider's own line, the "Nieuw" mark and the status pill that `DocumentsBlock` already draws never arrive.
- The line under a file says "Van de gemeente", which is wrong for a school's papers.

learniq's `guardian-reports-and-documents` declares the page; this change lets the site draw it.

## What changes

- **A documents block for each record.** A documents block MAY declare `eachRecord: true`. It then draws one group per row of its collection the subject may read, headed by the row's `titleFields` (else the block's `label`) with the row's `subtitleFields` (else the block's `subtitle`) beside the heading. Such a block stays on any page, not only on its collection's record page. Each group lists its documents through the existing per-object route, so every row is proven the subject's again before anything is listed or opened.
- **The block's own words.** A documents block MAY declare `subtitle` and `emptyLabel` (the sentence when a group lists nothing), both trimmed and at most 400 characters.
- **The entry's extras arrive.** The entry reader keeps `meta`, `isNew`, `status` and `statusTone` when they are a short text or, for `isNew`, a boolean.
- **A paper names no sender.** A document of a collection that is not a case collection reads its day, type and size ("2 oktober 2026 · PDF, 84 kB").

## Capabilities

### Modified capabilities

- `portal-contribution-contract`: ADDED requirements for `eachRecord` and the entry's extras.
- `site-mijn-omgeving`: ADDED requirement for the each-record groups and the paper line.

## Impact

- Backend: `lib/Contribution/ListBlockNormaliser.php` (`documentsKeys`, `recordPageOnly`), `lib/Contribution/BoardKeys.php` (`subtitle`, `emptyLabel`), `lib/Service/DocumentEntryExtras.php` (new), `lib/Service/PortalCaseDocumentReader.php`.
- Site: `src/site/pages/collections/collectionLoader.js`, `ContributionPage.vue`, `src/site/components/mijn/documents.js` (`eachRecordBlock`, `paperLine`), `DocumentsBlock.vue`.
- Register: none; contribution blocks are not stored as portal pages.

## Not in this change

- "Alle rapporten van Vera downloaden" (a bundle endpoint per record) and "Door u aangeleverd" (an upload by the guardian): both still open in `documents-grouped-per-record`.
