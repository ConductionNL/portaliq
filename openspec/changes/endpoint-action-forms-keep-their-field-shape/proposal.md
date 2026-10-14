---
kind: code
---

# Proposal: endpoint-action-forms-keep-their-field-shape

## Why

The live test of the Zuiddrecht demo on 14 October 2026 filed a Woo request through dossiq's two endpoint actions and found four faults on portaliq's side:

1. The period fields were plain text boxes. dossiq can only declare a field's `input` on the action itself, because an endpoint action writes no schema here, and `ActionConfigNormaliser` dropped a declared `input`. A resident typed "01-01-2026" and the request was refused.
2. On the dossier page the requester kind and the document kinds were plain text too. The attached-action listing (`AttachedActionResolver::LISTED`) left out `optionsProviders`, the only place a choice field's options live, and `attachedBody()` turned a list of choices into one string.
3. On the dossier page a refused answer read "Opslaan is niet gelukt.": the forward's status was dropped on the way to the form, so `refusalKey(0)` took a 400 for a failure, and the app's `errors` per field were never shown.
4. On the start page of the Woo request the title "Informatie opvragen (Woo-verzoek)" stood twice: the page's h1 and the action block's h2.

## What changes

- `ActionConfigNormaliser` keeps a declared `input` of `date`, `datetime`, `number` or `email`. On a create action the schema's format still decides.
- `AttachedActionResolver` lists `optionsProviders`; `attachedBody()` keeps a list answer as a list.
- `runAttachedAction()` returns the status; `AttachedActions::sendOpen()` answers through `forwardResult()`, so `errors` and `invalid` land on their fields as on an endpoint form.
- `ContributionPage` hands its title to an action block (`pageTitle`); `ActionBlock` leaves out its heading when it would repeat that title.

dossiq declares `input: date` on the Woo period fields in its own change (`portal-woo-request-form-takes-dutch-input`).

## Screens

WooVerzoek and MijnOverzicht (`screens-src/zuiddrecht/`): one title on the request page, dates asked as day, month and year, the requester asked as choices on the dossier page too.
