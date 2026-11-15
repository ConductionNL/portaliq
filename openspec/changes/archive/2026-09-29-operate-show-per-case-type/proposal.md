---
kind: code
depends_on: [portal-identity-and-the-organisations-cases, portal-intake-form-as-an-object]
---

# Proposal: operate-show-per-case-type

## Why

A municipality handles internal and sensitive case types in the same case
app as the ones residents apply for. It wants residents to see the permit
application in "My cases", and not the internal enforcement file about the
same address. Today the case app's code decides which case types reach the
portal. The municipality cannot switch one off.

The demand row, portaliq matrix, row `dem-tnd-show-per-casetype`, "Choose per
case type or service whether it is shown in the portal at all.", origin
`tender`, <https://www.tenderned.nl/aankondigingen/overzicht/402189>. The
matrix `originNote`, verbatim:

> TenderNed 402189 Gemeente Alkmaar Common Ground Platform: 'Aan kunnen geven welke workflows standaard getoond worden op MijnAlkmaar en welke niet' (nota van inlichtingen)

Rated `no`, `built.state` `none`. Its `built.note`, verbatim:

> Which case types appear is decided by the contributing app's manifest code (collections and an optional narrowing filter), not by an admin choice per case type or service. portalIdentityKind limits WHO may reach a type, not whether it is shown.

Two competitors are rated `yes`. `nl-portal`, verbatim:

> backend/zgw/zaken-api/src/main/kotlin/nl/nlportal/zakenapi/service/ZakenApiService.kt:91 zaakTypesIdsExcluded removes case types from the list [reached on API only (config property, no admin screen); set in configuration, not a UI]

`xxllnc-pip`, verbatim:

> backend/perl-api/lib/Zaaksysteem/Controller/Plugins/PIP.pm:152 prevent_pip per case type; backend/zaken/src/zsnl_domains/case_management/repositories/pip_acl.py:49 [reached on case type editor; was unknown from docs]

The lane recorded the row as `build` on the tender rule.

## What changes

- **A per-portal list of hidden case types.** The `portal` object carries
  the case types an administrator chose not to show. Everything else shows,
  as today.
- **A screen to choose.** On a portal's page in the admin app, a "Case
  types" page lists the case types the portal's contributions and published
  forms know, each with a "Show in this portal" switch.
- **Hidden means hidden everywhere in that portal.** A hidden type's cases
  leave "My cases", its case pages answer as if the case did not exist, and
  its request form no longer renders. The case app keeps the case; nothing
  is deleted.

## Rows this closes

| Matrix | Row id | Row name | Own rating | What is missing |
|---|---|---|---|---|
| portaliq | `dem-tnd-show-per-casetype` | Choose per case type or service whether it is shown in the portal at all. | no | An administrator's choice per portal and case type, enforced on the case list, the case page and the request form. |

## Existing work it builds on

- `portal-identity-and-the-organisations-cases` (open):
  `lib/Service/PortalCaseListReader.php` and its `caseTypeField`.
- `portal-intake-form-as-an-object` (open): published bindings, which are
  the portal's own list of the case types it takes requests for
  (`PortalFormBindingResolver::declaredCaseTypes()`).
- `portal-contribution-contract` (spec): collections of kind `cases`.

## Out of scope

- Who may reach a case type (`portalIdentityKind`). That stays the case
  app's declaration.
- Hiding single cases.
- Hiding a case type from staff.

## Sibling halves

- **Case apps (ConductionNL/dossiq and others with `cases` collections)** may
  declare `caseTypeSource: {register, schema, labelField}` on a `cases`
  collection, so the screen can name case types the portal has no form for.
  Without it the screen lists only the case types of published forms and
  those already seen on cases. Not written here.
