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

## Open decision

dossiq's Woo form (`startWooVerzoekAlgemeen`) is an endpoint action. Under REQ-RR3-005 it can therefore keep no draft, so the Woo boards' save and resume flow only lights up once one of these happens:

- **(a)** dossiq declares the Woo action as a `type: create` action on its case register and schema. This keeps decision 180. It is recommended.
- **(b)** portaliq brings back its own `portalDraft` store for endpoint actions. This reverses the drain decided with decision 180.

This change builds the dialogs and the list for every create action. It does not choose between (a) and (b).
