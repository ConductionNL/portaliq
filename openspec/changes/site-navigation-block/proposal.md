# Proposal: site-navigation-block

## Why

Ruben reviewed the primary-school parent portal recordings (2026-10-02). The site's header menu was far too crowded for a parent: about fifteen items over three rows, the school app's pages, the portal's own sections and the site pages side by side. His design: a grid item for the menu, placed on the left side of the pages.

## What changes

- A `siteNavigation` block (`SiteNavigationBlock.vue`), public, in the page designer's palette like every public block. It renders the same items the header menu shows as one vertical list in groups: each signed-in app's pages under the app's label, the shell's own sections under "My overview", each header menu (the site's pages) under its title. A child item follows its parent. The block is a `nav` landmark named "Menu", each group has a heading, the current page carries `aria-current="page"`, and the links are ordinary links (keyboard, new tab). At phone width the groups collapse behind a "Menu" button (`aria-expanded`, `aria-controls`).
- The shell derives the groups (`src/site/lib/siteNavigation.js`) and hands them to the block, like the glossary rows: the block fetches nothing, and a placement can rename the landmark but cannot change where links lead.
- When the side region holds the block, the region renders as a column LEFT of the content and first in the document (reading and tab order match the screen), also on the signed-in pages (`/mijn/...`), which have no page body of their own. On a phone it stacks above the content.
- A page that carries the block (side region or main grid) gets a header without its menu (`BrandHeader` `showNavigation`): logo, language, account, acting-for, branch and sign-out stay.
- The portal's `regions.aside` is the default for all its pages, so one portal setting gives a school the side menu everywhere. A docs page for administrators (`docs/operations/side-menu.md`).

## For the contributing apps

A school portal created by learniq gets the side menu by writing `regions.aside: [{ "id": "site-navigation", "widgetKey": "siteNavigation" }]` on the portal it provisions (`ExamplePortalProvisioner`). That is a learniq change.

## Not changed

- The page designer edits the main region only; the side region is set on the portal or page record.
- Portals without the block look exactly as before.
