---
kind: schema
---

# Proposal: portal-cms-content-model

## Summary

Add a `cms` register to OpenRegister carrying the five schemas Portaliq needs
to be the fleet's headless CMS: `website`, `menu`, `page`, `glossaryTerm` and
`media`. `menu`, `page` and the glossary already exist as OpenCatalogi-managed
objects — this change gives them a canonical home, adds the `website` they are
scoped to, and adds the fields multi-site and markdown need.

Chain link 3 of `hydra/openspec/changes/portaliq-phase-two`. Schema only: no
controller, no UI, no app-side logic. Portaliq's ownership of these objects is
link 5; OpenCatalogi's handover is link 9.

## Motivation

The fleet's public content model is currently defined by whichever app happens
to write it. `menu` and `page` are OR objects created by OpenCatalogi, with the
shape implied by `TMenu` / `TMenuItem` / `TMenuSubItem` in OC's TypeScript
entities rather than declared in a register. There is no `website`, so nothing
scopes content to a site, and Portaliq's own white-label requirement — which
would have needed one — is 0% implemented.

Declaring the model in OpenRegister (ADR-022, ADR-070) gives it RBAC, audit
trail, versioning and multi-tenancy for free, and means Portaliq, OpenCatalogi
and a Docusaurus build all read the same shapes.

## Affected Projects

- [ ] `openregister` — `lib/Settings/cms_register.json` (or a `register.d`
      fragment) declaring the five schemas; a repair step that back-fills a
      default `website` reference on existing `menu` / `page` / glossary
      objects so nothing is orphaned.

## Design notes

**The existing shapes are preserved, not redesigned.** `menu` keeps two-level
items with `order`, `name`, `link`, `description`, `icon`, `groups`,
`hideBeforeLogin`, `hideAfterLogin`. Anything else would be a data migration
across ten live municipal deployments for no gain.

**`page.body` is the one real addition** — `{ type: "grid" | "markdown", … }`,
where `grid` holds manifest-v2 `widgets[]` entries and `markdown` holds source
text. Existing page-content blocks map onto `markdown`.

**`website` carries what the unimplemented white-label requirement asked for**:
name, logo, theme reference (a themiq theme), domains with their verification
state, authentication configuration, locales, and publication status.

**Property naming follows ADR-011** and the fleet's English-code rule. The
Dutch property names on existing objects are data, not new code, and are out of
scope here.

## Risks

- **Back-filling a website reference touches every existing content object.**
  It must be idempotent and must never run as part of `<install>` (see the
  fleet's repair-hook convention). A partial back-fill leaves objects that
  resolve to no site — which, under the no-default-site rule, means they stop
  being served.
- **A schema that drifts from the manifest `$defs` breaks the grid.** `page.body`
  of type `grid` must hold exactly `$defs.widgetEntry` shapes; it references the
  canonical definition rather than restating it.
- Declaring five schemas at once is a large surface for one review. It stays
  one change because they are mutually referential — a `page` without a
  `website` is not reviewable.
