# Proposal: page-badge-key

## Why

The school portals' side menu shows a count beside an entry ("Oudergesprekken 1", "Berichten 2"; boards MijnOverzicht and MijnMenu, plan gap G-05). The resident menu of `resident-menu-badges-and-cards` (PR #1203) reads a page's `badge` to draw that count, but `PageMenuKeys` keeps only `menu`, `home`, `records` and `perRecord`, so the server drops `badge` and the menu shows no counts.

## What changes

- A contributed page MAY declare `badge: { collection, label? }`. `PageMenuKeys` keeps it when `collection` is one of the same contribution's collections, with `label` (text, at most 80 characters, `{count}` filled in by the site) when given. Anything else is dropped, so a badge can never count another app's rows.

## Not in this change

- Counting and drawing the badge: the site's resident menu (#1203).
- Declaring `badge` on a portalPage record (the register schema); a provider app declares it in its contribution.
