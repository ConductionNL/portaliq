---
kind: code
depends_on: [my-dossiers, site-reaches-portal-parity]
---

# Proposal: site-shared-dossier

## Why

A resident shares a dossier by link (hydra `woo-citizen-journey` J3.4). The
link opencatalogi handed out was its JSON read,
`/index.php/apps/opencatalogi/api/collections/shared/{token}`, so whoever
opened it saw raw JSON. Nobody can read a dossier that way, and the journey
cannot be filmed.

## What changes

- The Vue site (`/apps/portaliq/site`) gets a public route,
  `/gedeeld-dossier/{token}`. It reads opencatalogi's shared view as an
  anonymous visitor and shows the dossier's title, its note, and the documents
  in it that are public now, each with its link and note. It never shows the
  owner. A revoked or unknown link says so and tells the visitor what to do.
- The route renders from opencatalogi's answer, not from a CMS page, so it
  opens on every portal without configuration. The page is loaded on demand,
  so the site bundle stays within its budget.
- opencatalogi's `shareDossier` answers `link` as this page
  (`/index.php/apps/portaliq/site?route=/gedeeld-dossier/{token}`), in
  opencatalogi `feat/woo-dossier-detail` (its REQ-CCOL-009).

## Not in scope

- The React portal (`src/portal`) is frozen for features; it gets no shared
  page. The link opens the Vue site on either renderer.
