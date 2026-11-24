## Why

The FormulierOpslaan, FormulierHervatten and MijnZaken boards draw how a resident saves a half-filled form and comes back to it later:

- **FormulierOpslaan:** a dialog that says what saving does and for how long.
- **FormulierHervatten:** a dialog on reopening that offers to continue or to start again.
- **MijnZaken:** an "Onvoltooid formulier" card with "Formulier afmaken".

Live, saving shows only a sentence, reopening silently refills the answers, and nothing in Mijn Zuiddrecht lists an unfinished form.

## What Changes

- "Save and continue later" opens a save dialog with three choices: "Stoppen zonder opslaan", "Doorgaan met invullen" and "Opslaan en stoppen". The dialog names how long the answers are kept. "Opslaan en stoppen" saves the draft and leaves the form the way cancelling does.
- Opening a form for which the resident has a live draft asks first: "Verder met mijn antwoorden" restores the answers, and "Opnieuw beginnen" discards the draft.
- `GET /portal/api/drafts` lists the signed-in resident's live drafts: app, action, label, when last saved, and until when they are kept. Mijn zaken shows each draft as an "Onvoltooid formulier" card that opens its form.
- The draft answer (`GET /portal/api/drafts/{app}/{action}`) also carries `updatedAt`, the moment the resume dialog names.

Drafts stay what they are: the destination object in OpenRegister status `draft`, kept only for a `type: create` action that names its register and schema (decisions 180 and 195, REQ-RR3-005).

## Decision

Decided (a), 24 Nov 2026: dossiq's general Woo form (`startWooVerzoekAlgemeen`) is now a `type: create` action on its case register and schema (decision 180, a1), so it keeps a draft and the Woo boards' save and resume flow applies to it. The dossier variant (`startWooVerzoek`) stays an endpoint action and keeps no draft. The options were:

- **(a)** dossiq declares the Woo action as a `type: create` action on its case register and schema. This keeps decision 180. It is recommended.
- **(b)** portaliq brings back its own `portalDraft` store for endpoint actions. This reverses the drain decided with decision 180.

This change builds the dialogs and the list for every create action; (a) needed no portaliq code.
