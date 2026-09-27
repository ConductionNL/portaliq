# Proposal: cases-documents-on-the-case

## Why

A resident opens their case in the portal and sees file names, but cannot open a single one. The list they see is also not the list the organisation chose to show them.

The portaliq matrix, row `cas-documents-view`, `built.evidence`:

> src/portal/components/CitizenCase.jsx:181-186 lists state.data.documents as plain <li>{file.name}</li>, no download control; the generic FileList in PageView.jsx:96-127 (a different block, for filesDownload collections) DOES render a download button

and its `built.note`: "On the citizen-case view itself the citizen can see a document's name but has no way to download it from that screen; downloading only works through the separate generic detail/FileList block."

Row `cmp-cas-decision-download`, `built.evidence`: "no dedicated 'decision letter' concept anywhere in lib/ or src/; a decision letter would have to arrive as an ordinary file on a filesDownload-enabled collection, reachable only through the generic detail block (see cas-download-generic), not the citizen-case screen".

Row `cmp-cas-documents-published`, `built.evidence`: "same mechanism as cas-documents-view/cas-download-generic: server-side ownership re-verification (PortalFileReader) scopes files to the subject, but the citizen-case screen's own list has no download action".

Reading the code for this change found the third row is wider than the matrix says. `CitizenCaseController::show()` lists every file in the case object's folder (`lib/Controller/CitizenCaseController.php:153`, `PortalFileReader::listFiles()`), with no check that the organisation meant the resident to see it. Scoping by owner is there; scoping by publication is not.

No demand row is attached to these three rows. They sit in the `cases` area. The competitor cells rated `yes`, quoted from `gap-rows.json`:

- `cas-documents-view`, Open Inwoner Platform: "src/open_inwoner/cms/cases/views/status.py:312 documents list; src/open_inwoner/openzaak/services.py:333 _is_info_object_visible (status and confidentiality filter) [reached on case detail page, Documenten section]". No URL recorded.
- `cas-documents-view`, NL Portal: "frontend/packages/user-interface/src/pages/CaseDetailsPage.tsx:248 DocumentsList; backend/zgw/zaken-api/src/main/kotlin/nl/nlportal/zakenapi/service/ZakenApiService.kt:163 status and vertrouwelijkheid whitelist [reached on /zaken/zaak/:id documents section; needs Documenten API]". No URL recorded.
- `cas-documents-view`, xxllnc Zaken PIP: "backend/perl-api/root/tpl/zaak_v1/nl_NL/plugins/pip/case/view.tt:76 documents iframe; backend/zaken/src/zsnl_domains/document/repositories/database_queries.py:289 publish_pip filter [reached on /pip/zaak/<id> 'Documenten']". No URL recorded.
- `cas-documents-view`, MijnOverheid: "https://www.logius.nl/onze-dienstverlening/interactie/voorzieningen/mijnoverheid/mijnoverheid-lopende-zaken 'Als u een dossier aanmaakt kunt u daar ook bijlagen aan toevoegen, zoals brieven en uittreksels' [was partial]". URL: https://www.logius.nl/onze-dienstverlening/interactie/voorzieningen/mijnoverheid/mijnoverheid-lopende-zaken
- `cmp-cas-decision-download`, NL Portal: "frontend/packages/user-interface/src/pages/CaseDetailsPage.tsx:253 download link for each case document; backend/zgw/zaken-api/src/main/kotlin/nl/nlportal/zakenapi/web/rest/ZaakDocumentResource.kt:40 [reached on /zaken/zaak/:id documents section; the decision letter is downloaded as a case document; no separate besluit view]". No URL recorded.
- `cmp-cas-decision-download`, xxllnc Zaken PIP: "backend/perl-api/lib/Zaaksysteem/Controller/Plugins/PIP/File.pm:221 download of a document published to the PIP; frontend-mono/apps/my-pip/src/components/PipCaseDocuments/PipCaseDocuments.tsx:141 [reached on /pip/zaak/<id>; the decision is a published document, no dedicated decision view]". No URL recorded.
- `cmp-cas-decision-download`, MijnOverheid: "kept from the morning docs pass of 2026-09-26; today's docs silent: mijn.overheid.nl/vragen/: 'Homeowners and renters receive their assessment digitally in the Message Box' and messages/attachments can be downloaded ('Document Download')". No URL recorded.
- `cmp-cas-documents-published`, Open Inwoner Platform: "src/open_inwoner/openzaak/services.py:333 _is_info_object_visible filters on document status and max confidentiality (src/open_inwoner/openzaak/models.py:415) [reached on case detail page, Documenten; was unknown from docs]". No URL recorded.
- `cmp-cas-documents-published`, NL Portal: "backend/zgw/zaken-api/src/main/kotlin/nl/nlportal/zakenapi/service/ZakenApiService.kt:163 only whitelisted status (default definitief, gearchiveerd) and vertrouwelijkheid; documentation/features/zaakinformatieobjecten-filtering/zaakinformatie-object-filtering.md:11 [reached on /zaken/zaak/:id documents section]". No URL recorded.
- `cmp-cas-documents-published`, xxllnc Zaken PIP: "backend/perl-api/lib/Zaaksysteem/Controller/Plugins/PIP/File.pm:192 publish_pip check; database_queries.py:289 [reached on /pip/zaak/<id>]". No URL recorded.

## What changes

- **The case app says which documents the resident may see.** The case collection names a documents method on the app's own portal provider, the same way it already names its timeline method. The app returns the documents it published to the applicant for one case.
- **Every listed document opens.** Each entry on the case screen is a download. The bytes are streamed through OpenRegister's file store after the case is proven to be the resident's and the document is proven to be on that case's list.
- **The decision comes first.** An entry the app marks as a decision is shown at the top, with its date, under its own heading.
- **The resident's own uploads stay visible.** Files the resident added through the portal are tagged when they are written, and shown under "Sent by you".
- **Nothing else in the case folder is listed.** Without a documents method, the resident sees only their own uploads. The unfiltered folder listing on the case screen goes.

## Rows this closes

| Matrix | Row id | Row name | Own rating | What is missing |
|---|---|---|---|---|
| portaliq | `cas-documents-view` | See the documents already attached to your case. | partial | A download on the case screen itself. |
| portaliq | `cmp-cas-decision-download` | Download the decision letter on your case. | partial | A decision the case app can mark, shown and downloadable on the case screen. |
| portaliq | `cmp-cas-documents-published` | See the documents the organisation published to you on a case, and only those. | partial | A publication filter decided by the case app; today every file in the folder is listed. |

## Existing work it builds on

- `openspec/changes/what-the-citizen-may-write-on-their-own-case` (open): the citizen case screen, `CitizenCaseController` and the document upload this change tags.
- `openspec/changes/archive/2026-07-23-portal-document-download` and `openspec/specs/supplier-portal/spec.md`, requirements "Scoped file download re-verifies ownership before serving a byte", "Identical-404 discipline (no existence oracle)" and "Download emits an audit hook". The case download keeps all three.
- `openspec/specs/portal-contribution-contract/spec.md`: the collection's `timeline.provider`, whose pattern the documents method copies (`lib/Service/PortalTimelineReader.php`, portaliq#723).

## Out of scope

- The generic detail block's `FileList` for collections that declare `filesDownload`. It keeps working as it does.
- Deciding what "published" means for a case type. That is the case app's rule: a status, a confidentiality level, a publish flag.
- Previewing a document in the browser. A download is enough to close these rows.
- Signing a document. The helper-b change `case-actions-sign-a-document` covers it.

## Sibling halves

- **ConductionNL/dossiq owes** a `caseDocuments` method on its `PortalContributionProvider` and the `documents` key on its `mijnZaken` collection. Its documents are OpenRegister objects of schema `document` linked to a case through `caseDocument`, and to a decision through `decisionDocument` (dossiq `lib/Settings/dossiq_register.json`). The `document` schema already carries `status` (`in_bewerking`, `for_determination`, `final`, `archived`) and `confidentiality`, so the rule for what a resident sees can be written there. A document linked through `decisionDocument` is returned with `kind` `decision`.
