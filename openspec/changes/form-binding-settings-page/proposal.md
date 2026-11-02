# Proposal: form-binding-settings-page

## Why

The board [portaliq/PtFormulierInstellingen](https://identity.conduction.nl/screens/board?id=portaliq/PtFormulierInstellingen) gives each request form its own settings page with tabs (Algemeen, Bevestiging, Verklaringen, Mede-ondertekenen, Betaling, Afspraak, Beschikbaarheid, Bewaartermijn, Doorsturen). The settings already live on the `portalFormBinding` object, but the Request forms list opens no page for one form: only the generic edit dialog of flat fields.

## What Changes

- A detail page `/form-bindings/:id` (FormBindingDetail) over `portalFormBinding`, so a row of Request forms opens it.
- Tabs as manifest config over nextcloud-vue's `sections`, `settings-form` and `audit-trail`: Algemeen (cards Formulier, Inloggen, Status), Bevestiging, Verklaringen, Mede-ondertekenen, Historie.
- No schema change: every field is an existing path of `portalFormBinding`.

## Not in this change

The tabs Betaling, Afspraak, Beschikbaarheid, Bewaartermijn and Doorsturen: the binding holds no such settings yet (payment lives on the case type's `portalFee`). They follow when form-governance-availability-retention-and-routing adds the fields.
