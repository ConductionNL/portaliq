# Design: life-domain-theme-pages

## Screens

The page follows the Zuiddrecht board **ThemaOverzicht** ("Mijn Zuiddrecht: parkeren", canvas `5NkFW28vZUUij43xzxHg5a`).

| Board element | Source |
|---|---|
| Resident menu with a group "Thema's" holding "Parkeren", current item marked | `portal.themes`, shown when at least one theme has content for this resident |
| Heading "Parkeren", intro "Uw parkeervergunningen, wat u nog moet doen en wat u kunt regelen, op één plek." | `portal.themes[].title`, `.intro` |
| "Wat moet ik regelen", "Bekijk alle taken (3)", task rows with case line and "Voor 18 oktober" chip | tasks collections tagged with the theme; the link goes to Mijn taken filtered on the theme |
| "Wat kan ik regelen": cards with title and one line ("Kenteken wijzigen", "Rijdt u in een andere auto? Zet het nieuwe kenteken op uw vergunning.") | actions tagged with the theme whose `when` holds |
| "Mijn parkeervergunningen", "2 vergunningen", rows with title, "Geldig" tag, meta line, "Geldig tot en met", "Bekijk alle parkeervergunningen" | collections tagged with the theme and `kind: products` |

A theme with nothing for this resident shows the intro and "Er is nu niets voor u bij {theme}." It is not listed in the menu.

## Data

`portal` gains `themes`: a list of `{slug, title, intro, productsLabel}` (schema.org `CategoryCode`). Portal schema 0.12.0 to 0.13.0.

A contribution collection or action MAY declare `theme` (a slug). A collection MAY declare `kind: products` with `validUntilField` and `metaFields`. The normaliser keeps `theme` only when the portal declares that slug.

## Change a held product

A product row's actions are the contribution's row actions (case-actions-row-inputs-and-conditions). "Kenteken wijzigen" is an `update` action on the product collection with its input fields; portaliq renders it with the existing action form and writes through the contribution's write path. Portaliq adds no endpoint (ADR-022).

## Rules on the product

An action with `when: {field, op, value}` on a product collection shows on the theme page only when at least one of the resident's products in that collection satisfies it. The operators are those row-action conditions already accept (`update-row-action-condition`).
