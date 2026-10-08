---
title: Theme pages per life domain
sidebar_label: Theme pages
---

# Theme pages per life domain

A resident with a parking permit thinks "parkeren", not the name of an app. A portal can group a resident's items by life domain: each theme gets a page in Mijn omgeving with what to arrange, what can be arranged and the products the resident holds.

## Declare the themes

Set `themes` on the portal record, a list of:

| Key | Meaning |
|---|---|
| `slug` | Lower case letters, digits and hyphens. The page is `/mijn/thema/{slug}`. |
| `title` | The name in the menu and the page heading. |
| `intro` | The sentence under the heading. |
| `productsLabel` | What the products are called, for example `parkeervergunningen`. |

## Tag the contribution

A contributing app puts `theme: "<slug>"` on a collection or an action. A tag the portal does not declare is dropped. The page shows:

- **Wat moet ik regelen**: the rows of collections tagged with the theme (not `kind: products`), with a link to all tasks.
- **Wat kan ik regelen**: the tagged actions. An action with `when: {field, op, value}` shows only when at least one product the resident holds in its collection satisfies it. The operators are `eq`, `neq` and `in`. A condition with no product to test against does not hold.
- **Mijn {producten}**: collections with `kind: products`.

A theme is listed in the menu, under the group "Thema's", only when something is tagged for that resident. A theme with nothing to show says "Er is nu niets voor u bij {thema}."

## Products

A `kind: products` collection can declare `titleField`, `validFromField`, `validUntilField`, `metaFields` and `countLabel` (`{singular, plural}`). Each row shows its title, a tag, a meta line and "Geldig tot en met":

| Tag | When |
|---|---|
| Geldig | no last day, or the last day is today or later |
| Verlopen | the last day has passed |
| Gaat in op {datum} | the first day is in the future |

The page shows at most three products, valid ones first. A link shows the whole list, expired ones last, when there are more than three or any has expired. Portaliq stores no product: the rows come through the contribution's scoped read.

## Change a product

An action of `type: update` with the theme and the product collection's register and schema shows as a button on each product row whose `when` holds. It opens the action's fields with the row's current values and writes through the contribution's update. After a save the row shows the new value.

## Not built

- The full list has no address of its own (`/mijn/thema/{slug}/producten`): it opens in the same page.
- Create and endpoint actions in "Wat kan ik regelen" are listed, with their text, but do not open their form from the card yet.
- "Bekijk alle taken" opens Mijn taken, not a list filtered on the theme.
