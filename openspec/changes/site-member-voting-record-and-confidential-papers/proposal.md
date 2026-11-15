# Proposal: site-member-voting-record-and-confidential-papers

## Why

Decision 83 (Ruben, 7 Oct 2026) gives portaliq the public-site pages for two decidiq rows. decidiq contributes the data (`decidiq/portal-voting-record-and-confidential-papers`); portaliq renders it.

- **pub-14**: a resident wants to see how a council member voted. NotuBiz, iBabs and GO show this on the public council site. decidiq's ORI API returns the votes as JSON. No page shows them.
- **plt-02**: a burgerlid or outside adviser without a Nextcloud account must read a confidential paper. Portaliq signs people in with DigiD, eHerkenning or eIDAS through integriq's broker (archived `2026-09-29-signin-integriq-broker-login`), and the session carries a trust level. No page offers decidiq's papers to such a session.

Two contract pieces are missing for this. A contribution cannot hand portaliq a public record that is not a plain OpenRegister collection, so decidiq's vote visibility rule cannot be reused. And the `documents` provider works only on case collections, with a download audit that fails open.

## What changes

1. **Contract: `publicRecords`.** A contribution MAY declare `publicRecords: [{id, label, group?, listProvider, recordProvider}]`. Both name public methods on the provider, held to the timeline rule (plain identifier, never a contract method). `listProvider()` returns `[{id, title, subtitle?, image?}]`. `recordProvider(id)` returns `{title, subtitle?, summary[], columns[], rows[], note?}` or null. Portaliq calls them with no subject, renders what they return, and never widens it. A record id the list does not hold answers 404.
2. **Site block: public records.** A new widget `publicRecords` for CMS pages. The editor picks a contributed record list. The block shows the list (name, role and party, a search box), and with `?record=<id>` on the same page it shows one record: the heading, the summary as figure cards, the rows as a table and the provider's note. It works without JavaScript for the list and the record, like the publication detail block.
3. **Contract: `documents` on any collection.** The `documents: {label?, provider}` key, today honoured on case collections only, is honoured on every listable collection. A collection's detail card on `/mijn/{app}/{page}` shows a Documents section. A download goes through a new route that reads the object in the subject's scope, applies the collection's `minTrust`, looks the document up again in the provider's answer and streams it.
4. **Contract: `documents.opened`.** The documents key MAY name an `opened` provider method. Portaliq calls it before streaming, with the object id, the document id and the subject (subjectRef, trust, identity type). When it returns anything but true, portaliq streams nothing and answers 503 "The paper cannot be opened right now". This is fail-closed, unlike `PortalAuditHook::download`, which stays as it is.
5. **Asking for a higher login.** When a signed-in resident opens a link to a page whose collection needs a higher trust than the session has, the page says which login is needed ("Log in met DigiD om dit stuk te lezen") and offers it. Today the collection is silently absent.
6. **Example site.** Zuiddrecht gets the public page "Hoe stemden de raadsleden" under Gemeenteraad, carrying the block over decidiq's `memberVotingRecords`.

## Rows

Source: portaliq `openspec/parity/capabilities.json`.

- **sib-decidiq-plt-02** Open confidential papers after signing in with DigiD or eHerkenning (building, partial). Its sign-in half is built. This change adds the paper view.
- **sib-decidiq-pub-14** Read a council member's public voting record on the website. Added in this round (`source: spec-round-2026-10-07`, competitor cells unknown).

## Not changed

- What is public, and who may read a confidential item. decidiq decides both.
- The sign-in itself. Live DigiD and eHerkenning wait on decision D1 (broker vendor and contract, ConductionNL/integriq#1495).
- `PortalAuditHook::download`, which keeps failing open for every other download.
