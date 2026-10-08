---
kind: code
---

# Proposal: life-domain-theme-pages

## Why

A resident with a parking permit thinks "parkeren", not "dossiq" or "zaak 2026-0061". Today Mijn Zuiddrecht groups everything by the app it comes from. Her open tasks about the permit sit on Mijn taken, her permit sits nowhere, and changing the licence plate means finding the right form on the public site.

NL Portal ships a theme page per life domain with tasks, products, "Wat kan ik regelen" decided by rules on the product, and a page to change a held product (`ThemeOverviewPage.tsx:40`, `ThemeDetailsPage.tsx:73`, `ThemeMutatePage.tsx`). They live in its component library and are routed only in its demo shell, so an implementer wires them. The Zuiddrecht board **ThemaOverzicht** draws the same page for Parkeren.

## What changes

- **A theme page per life domain.** A portal declares themes (Parkeren, Inkomen) in `portal.themes`. Each theme gets a page under Mijn Zuiddrecht and its own group "Thema's" in the resident menu.
- **The page shows three blocks,** as the board draws: "Wat moet ik regelen" (open tasks of the theme, with "Bekijk alle taken"), "Wat kan ik regelen" (actions offered for the theme) and "Mijn {producten}" (the products the resident holds, with "Geldig tot en met").
- **A contribution tags its rows with a theme.** A collection or an action declares `theme: parkeren`; portaliq gathers every tagged collection and action of every contribution onto that theme page.
- **What you can arrange follows rules on the product.** An action may declare `when`, a condition on the held product's fields (the existing row-action conditions), so "Bezoekersuren kopen" shows only with a visitor permit.
- **Change a held product.** A product row may offer an update action ("Kenteken wijzigen"), which opens the contribution's own form for that row and writes through the contribution, as every update action does.

## Rows covered

- `cas-life-domain-themes`, `prd-product-actions`, `prd-change-held-product` (decision 101).
- `dem-rm-my-products` (reopened by decision 105, 8 October 2026): the products half of the board, "Mijn parkeervergunningen" and "Bekijk alle parkeervergunningen".

## Decided: the products a resident holds

Decision 105 (8 October 2026) reopened `dem-rm-my-products`, decided no on 27 September, because the ThemaOverzicht board draws it. The decision keeps the September boundary: portaliq builds no product register. It renders the products a domain app contributes as a collection with `kind: products` (dossiq for parking permits), with their validity, on the theme page and on a full list per theme. The domain app owns the records and their dates.

## Out of scope

- A product catalogue or Open Product binding. The app that issues the product (dossiq for permits) owns the records.
- DMN rule evaluation. `when` conditions are the field comparisons portaliq already evaluates.
