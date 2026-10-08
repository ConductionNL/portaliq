---
kind: code
---

# Proposal: form-statements-intro-and-confirmation-mail

## Why

A resident starts a Woo request without knowing what she needs or how long it takes, and halfway through finds she needs a date range she does not have at hand. When she sends it, nothing asks her to confirm that her answers are true or that she accepts the privacy statement, although many forms legally need both. Afterwards she gets a reference on screen (`lib/Controller/PortalIntakeController.php:235` `confirmationText`) and no mail with what she sent.

Open Formulieren 4.0.1 has an introduction page per form (`src/openforms/forms/models/form.py:291`), privacy consent and a statement of truth as required checkboxes (`form.py:230`, `:239`, `sdk:src/components/StatementCheckboxes`), and a confirmation page and confirmation e-mail per form with the answers and the reference (`form.py:170`, `:223`, `src/openforms/emails/models.py:39`).

Four Zuiddrecht boards draw this: **FormulierStart** ("Voordat u begint", "Hoe wilt u verdergaan?"), **WooVerzoekControleren** (the block "Verklaringen" with two checkboxes), **WooVerzoekVerstuurd** (heading, reference, "Wij hebben een bevestiging gestuurd naar ... met een samenvatting van uw aanvraag", "Wat gebeurt er nu?", PDF, print) and **PtFormulierInstellingen** (tabs Bevestiging and Verklaringen).

## What changes

- **An introduction page.** A form can open with a page that says what you need, how long it takes and what happens next, and lets you choose how to continue (with DigiD or without).
- **Statements before sending.** The review step ends with "Verklaringen": a statement of truth and privacy consent, each required when the binding says so. The server refuses a submission without them and records which text was accepted when.
- **A confirmation page per form** with a heading, the reference, the decision date when the case type gives one, the steps that follow, "Download uw aanvraag als PDF", "Naar mijn zaken" and "Deze pagina printen".
- **A confirmation e-mail** with a summary of the answers, the reference and the PDF, sent to the address in the form.

## Rows covered

- `int-statement-consent`, screens WooVerzoekControleren and PtFormulierInstellingen.
- `int-form-intro-and-confirmation`, screens FormulierStart, WooVerzoekVerstuurd and PtFormulierInstellingen.

Both from decision 104.

## Builds on

- `site-multi-step-forms`: the review step and the confirmation page with focus on its heading (REQ-SMF-011). This change adds the statements and the richer confirmation.
- `mail-templates-admin-screen`: the confirmation mail is template `form-confirmation` there.
- `2026-07-23-wmebv-submission-receipts`: the receipt log and PDF copy (`SubmissionReceiptService`), reused for the PDF and for "Bevestiging mislukt".
- buildiq `form-confirmation` (built) writes a confirmation text on the form; the binding's `confirmation` overrides it per portal.

## Out of scope

- The feedback question "Hoe vond u dit formulier?" on WooVerzoekVerstuurd (`cmp-ana-feedback`).
- The co-sign and payment lines on WooVerzoekVerstuurd (`resident-identity-in-forms`, `intake-pay-on-submit`).
