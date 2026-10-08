---
kind: code
---

# Proposal: form-governance-availability-retention-and-routing

## Why

A municipality opens a subsidy for 150 front gardens on 1 January and has to close it by hand when the 150th request arrives. A Woo request that should go to the environment team lands in the general case queue. A submission whose case could not be created sits as `failed` until someone happens to look. Nothing ever deletes a submission. And when the team leader asks for this month's requests in a spreadsheet, there is no button.

Open Formulieren 4.0.1 sets all of this per form: open and close dates and a submission limit (`src/openforms/forms/models/form.py:321`, `:327`, `:153`), a registration backend per form (`src/openforms/registrations/contrib/`), retry and a daily digest of failures (`src/openforms/registrations/tasks.py:107`, `src/openforms/emails/digest.py:73`), removal limits per kind of submission (`form.py:335-395`, `src/openforms/data_removal/tasks.py:21`) and an export of submissions (`src/openforms/submissions/admin.py:455`).

Portaliq draws these on four boards: **PtFormulierInstellingen** (the tabs Algemeen, Beschikbaarheid, Bewaartermijn, Doorsturen and "Inzendingen deze maand 41 van maximaal 500"), **FormulierNietBeschikbaar** (what the resident sees when a form is closed, full or in maintenance), **FormulierVerwerken** (processing, and "Het versturen is niet gelukt" with "Opnieuw proberen") and **PtInzendingen** with **PtInzending** (the list with "Downloaden" and the filter "Bevestiging mislukt").

## What changes

- **Availability.** A form binding gets an active-from and active-until date, a maximum number of submissions per period, a maintenance window with its own text, and a pointer to the form that replaced it. The resident sees the matching situation from FormulierNietBeschikbaar.
- **Where a submission goes.** A binding names its target: the case type's app (today's only path), a mailbox, or an integriq connection for ZGW, Objects API, StUF-ZDS or a JSON endpoint, with rules that pick a target from the answers ("gaat het over milieu, dan naar milieu@zuiddrecht.nl").
- **Retry and digest.** A failed registration is retried in the background, can be retried by the resident from the processing page and by staff from the submission, and the portal's administrators get one daily mail listing what failed.
- **Retention.** A binding keeps completed, unfinished and failed submissions for a set number of days each, then deletes or anonymises them.
- **Export.** Staff who manage a form can download its submissions as CSV or XLSX. The list download on PtInzendingen stays without answers, as the board says.

## Rows covered

- `int-form-schedule-limit`, screens FormulierNietBeschikbaar and PtFormulierInstellingen.
- `int-registration-per-form`, screen PtFormulierInstellingen.
- `ops-registration-retry-digest`, screens FormulierVerwerken, PtInzendingen and PtInzending.
- `ops-submission-retention`, screen PtFormulierInstellingen.
- `ops-submissions-export`, screen PtInzendingen.

All from decision 104.

## Who owns what

- integriq owns the external registration targets (ZGW, Objects API, StUF-ZDS, JSON). Portaliq hands over the submission and the named connection; it builds no ZGW or StUF client (ADR-022).
- OpenRegister owns deletion and export of objects. Portaliq sets the expiry on each submission object and asks OpenRegister's export for the rows (ADR-022).

## Out of scope

- The fourth situation on FormulierNietBeschikbaar, a fault in a connection. It belongs to the service fetch in `data-lookups-and-checks-in-forms`.
- Payment and appointment tabs of PtFormulierInstellingen (`intake-pay-on-submit`, `cmp-tsk-appointment`), and the Bevestiging, Verklaringen and Mede-ondertekenen tabs (`form-statements-intro-and-confirmation-mail`, `resident-identity-in-forms`).
