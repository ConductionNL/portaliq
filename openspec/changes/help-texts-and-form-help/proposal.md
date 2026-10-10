---
kind: code
---

# Proposal: help-texts-and-form-help

## Why

Halfway through a Woo request a resident does not know what to write in "Welke informatie wilt u ontvangen?". She closes the tab, because the only way to get help is to find the contact page and lose her answers. On Mijn taken another resident wonders what a task is and who sent it. The portal explains neither.

Open Inwoner keeps a help text per section, edited in the admin (`src/open_inwoner/configurations/models.py:317`, `:325`, `:333`). Open Forms offers a help dialog on a form. The Zuiddrecht board **FormulierHulp** draws that dialog: phone, opening hours, the desk, "Vraag per e-mail", and the promise that answers stay.

## What changes

- **Hulp nodig? on a form.** A form shows "Hulp nodig?" at the top. It opens a dialog with an intro, an optional image, Telefoon with a line on what to say, Openingstijden, Balie, "Vraag per e-mail" and Sluiten. The answers stay as they were.
- **The contact details are set once.** The portal holds default help details; a form may override them.
- **A help text per section.** An editor writes a short help text per page, and per part of Mijn omgeving (overzicht, zaken, taken, berichten). A "Hulp bij deze pagina" control under the heading opens it.

## Rows covered

- `int-form-help` (decision 102), screen FormulierHulp.
- `site-page-help-text` (decision 101), no board yet: it is on the design session's missing-boards list. The builder follows design.md until a board exists.

## Out of scope

- Chat or a callback request from the dialog.
- Help texts per form field. Fields already carry their own description.
