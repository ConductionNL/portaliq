---
kind: code
depends_on: [woo-search-and-detail, portal-federated-search]
---

# Proposal: woo-journey-entry-points

## Why

The Woo citizen journey (hydra `openspec/changes/woo-citizen-journey`) runs
through the portal at five points, and none of them exists yet:

- A signed-in resident cannot keep a publication or a document (J3.1).
- A signed-in resident cannot save a search to get updates (J6.1).
- A dossier has no "Stel een vraag over dit dossier" (J4.1) and no "Start een
  Woo-verzoek" (J5.1). Those actions belong to pipelinq and dossiq, and the
  portal can only show an action on the collection of the app that declares it.
- Removing a portal account leaves the resident's dossiers and saved searches
  behind in opencatalogi, because portaliq raises no event for it (C7).
- A notice that pipelinq, dossiq or opencatalogi writes into the portal inbox
  reaches the inbox only. The email never goes, because portaliq skips every
  `portalMessage` it did not write itself (C3).

## What changes

- **Save buttons on the public site, for signed-in residents only.** "Bewaar in
  mijn dossier" on the publication page and on each document. "Bewaar deze
  zoekopdracht" on the search block. Both call opencatalogi's portal actions
  through `/portal/api/actions/opencatalogi/{actionId}` with the resident's
  portal session. An anonymous visitor, or a portal without opencatalogi's
  actions, sees neither.
- **Actions that attach to another app's collection.** An endpoint action may
  declare `attachTo: { app, schema }` and a `rowField`. The portal shows it on
  the detail of that app's collection, asks its fields, and forwards it with the
  proven row id. That is how pipelinq's `askAboutDossier` and dossiq's
  `startWooVerzoek` appear on the dossier page.
- **An event when a portal account is removed.**
  `OCA\Portaliq\Event\PortalAccountRemovedEvent` carries the subject reference.
  opencatalogi listens and deletes that subject's dossiers and saved searches.
- **Notices from other apps get their email.** A `portalMessage` written by
  another app with a `ruleKey` that app declares goes out by email, the same way
  portaliq's own change notices do.

## Hydra requirements it implements

- "The public site MUST learn only whether a resident is signed in"
- "Removing a portal account MUST remove the resident's dossiers and saved searches" (the portaliq half)
- "Every answer, decision and alert MUST reach the resident through portaliq's notice path"
- "A question about a dossier MUST carry a snapshot of the dossier, not access to it" (the portal half: the action on the dossier page)
- "A Woo request MUST be created by one dossiq path, from the portal and from pipelinq alike" (the portal half)

## Deviations from the contract

- **No `GET /api/site/me`.** The public site already knows who is signed in: it
  keeps the portal bearer in `sessionStorage` and reads
  `GET /portal/api/session` on every load (`src/site/lib/authApi.js`
  `fetchSession()`), which answers `{ authenticated: false }` without a bearer.
  A second endpoint would answer the same question from the same session. The
  site blocks take `signedIn` from the page shell instead. Fixed in hydra
  `design.md` C7.
- **Berichtenbox is not reached for the three new rule keys.** The message box
  channel asks the sending app for the recipient's identity
  (`recipientProvider`). pipelinq, dossiq and opencatalogi store no BSN in this
  journey, so there is nothing to ask. Inbox and email work. Named in
  STATE.md as a decision for Ruben.

## Out of scope

- The dossier pages themselves: `my-dossiers`.
- The opencatalogi, pipelinq and dossiq actions behind the buttons.
