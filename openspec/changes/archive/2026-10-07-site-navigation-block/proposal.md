# Proposal: site-navigation-block

## Why

Ruben reviewed the primary-school parent portal recordings (2026-10-02). The site's header menu was far too crowded for a parent: about fifteen items over three rows, the school app's pages, the portal's own sections and the site pages side by side. His design: a grid item for the menu, placed on the left side of the pages.

## How it composes with site-resident-menu (#1086)

#1086 landed first: the blue bar now carries only the website's pages, and the resident's own items sit in a menu beside the content on every `/mijn` page (`ResidentMenu`). This change adds what that does not cover: a block a portal or page places on its CMS pages (the school's home and information pages), showing the resident's items in the same groups as `ResidentMenu` plus the site's pages. On `/mijn` the resident menu stays as it is, and the block's column is not shown there, so no page carries two menus.

## What changes

- A `siteNavigation` block (`SiteNavigationBlock.vue`), public, in the page designer's palette like every public block. It renders one vertical list in groups: signed in, the resident's own items in the groups of the menu beside `/mijn` (`residentMenuGroups`), then each header menu (the site's pages) under its title. A child item follows its parent. The block is a `nav` landmark named "Menu", each group has a heading, the current page carries `aria-current="page"`, and the links are ordinary links (keyboard, new tab). At phone width the groups collapse behind a "Menu" button (`aria-expanded`, `aria-controls`).
- The shell derives the groups (`src/site/lib/siteNavigation.js`) and hands them to the block, like the glossary rows: the block fetches nothing, and a placement can rename the landmark but cannot change where links lead.
- When the side region holds the block, the region renders as a column LEFT of the content and first in the document (reading and tab order match the screen) on CMS pages. On a phone it stacks above the content.
- A CMS page that carries the block (side region or main grid) gets a header without its menu (`BrandHeader` `showNavigation`): logo, language, account, acting-for, branch and sign-out stay.
- The portal's `regions.aside` is the default for all its pages, so one portal setting gives a school the side menu everywhere. A docs page for administrators (`docs/operations/side-menu.md`).

## For the contributing apps

A school portal created by learniq gets the side menu by writing `regions.aside: [{ "id": "site-navigation", "widgetKey": "siteNavigation" }]` on the portal it provisions (`ExamplePortalProvisioner`). That is a learniq change.

## Not changed

- The page designer edits the main region only; the side region is set on the portal or page record.
- Portals without the block look exactly as before.
