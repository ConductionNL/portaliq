---
kind: code
---

# Proposal: data-lookups-and-checks-in-forms

## Why

A resident types her postcode and house number and then has to type her street and town as well. She types an IBAN with one digit wrong and only hears about it weeks later. A moving form asks her to type her partner's and children's names, although the BRP knows them. A permit form cannot check with the Kadaster whether she owns the building. A list of housing types is copied into every form by hand. And a parking permit form cannot let her pick the kind of permit and take its price from the product.

Open Formulieren 4.0.1 does each of these: address lookup from BAG (`src/openforms/formio/components/custom.py:681` addressNL, `src/openforms/contrib/kadaster/`), Dutch format checks (`custom.py:514` bsn, `:1112` iban, `:1149` licenseplate, `src/openforms/validations/validators/formats.py:40`, `:69`), reference lists (`src/openforms/contrib/reference_lists/client.py`), family members from the BRP (`src/openforms/prefill/contrib/family_members/plugin.py:63`), service fetch during the form (`src/openforms/api/v2_urls.py:63`), and on its 4.1 roadmap price options from Open Product (#6655, #6729, #6731).

What portaliq has: a binding flag `addressLookup` (`lib/Settings/portaliq_register.json:7206`) that travels in the render payload (`PortalFormBindingResolver.php:252`) but that nothing in `src/site` reads; type checks only in `PortalFormValidator::checkValue()` (`:143`); applicant prefill without family (`PortalApplicantPrefill`).

The Zuiddrecht boards **FormulierVelden** (address block, BSN, IBAN, licence plate, phone, reference list, the Kadaster ownership check) and **FormulierGezinsleden** ("Wie verhuist er mee") draw these. **FormulierNietBeschikbaar** situation 4 draws what happens when a connection fails. Price options have no board yet.

## What changes

- **Address from postcode and house number,** read from OpenRegister's BAG register, with street and town filled in and editable.
- **Dutch format checks** on BSN, IBAN, licence plate, Dutch and international phone numbers, postcode, KvK number and branch number, while typing and on the server.
- **Choice lists from a reference list** maintained once, resolved on the server.
- **Family from the BRP:** partner and children on the same address, to choose who the request is about.
- **Service fetch:** a step can fetch data from a connection through integriq, using earlier answers, and show the result as a read-only line or a check. A failed fetch shows situation 4 and keeps the draft.
- **Price options:** a field that offers the variants of a product from the portal's product catalogue, whose chosen price is the amount to pay.

## Rows covered

- `int-address-lookup`, `int-dutch-field-checks`, `int-reference-lists`, `int-service-fetch`: screen FormulierVelden (and FormulierNietBeschikbaar for the fault).
- `int-family-prefill`: screen FormulierGezinsleden.
- `int-product-price-options`: no board yet (on the missing-boards list).

All from decision 104.

## Who owns what

- OpenRegister owns BAG, BRP and the reference list data (`lib/Settings/bag_register.json`, `BrpPersonProvider`). Portaliq queries them; it stores none of it (ADR-022).
- integriq owns connections to outside services; portaliq calls a named integriq source from the server.
- buildiq authors formats, reference-list choices and fetch steps in the form designer (`buildiq/data-field-types-and-choice-lists`, row `data-choice-lists`; buildiq `form-address-lookup` is decided-no on its side, so the portal reads the address itself).

## Needs a decision

`intake-pay-on-submit` says the portal holds no price and takes the fee from the case type. A price per product variant comes from the portal's product catalogue (the Kosten block on PtProduct). This change keeps the case type's fee as the default and lets a variant's catalogue price replace it only when the form has a `productVariant` field. Both are read on the server, never from the browser.
