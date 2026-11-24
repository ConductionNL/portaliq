---
title: Shared page blocks
sidebar_label: Shared page blocks
description: How an editor keeps one block of widgets, such as contact details and opening hours, for every portal of the organisation and changes it in one place
---

# Shared page blocks

An organisation with more than one portal often shows the same block on each of them: contact details, opening hours, a notice about a holiday. A shared block keeps that block once. You place it on the pages of each portal, and when you change the block every page that places it shows the change.

A shared block belongs to an organisation, not to a portal. Only the portals of that organisation can show it.

## Make a shared block

1. Open **Shared blocks** in the portaliq admin. The menu shows it under the settings to administrators; a page editor who is not an administrator opens it at `/shared-blocks` in the app.
2. Choose **Add** and give the block a title, for example "Contact and opening hours", and the organisation it belongs to. A new block is a draft.
3. Choose **Layout** on the block's row, or on the block's own page. The page designer opens on the block.
4. Build the block the same way you build a page: drag widgets from the palette into the grid and fill them in.
5. Save, and publish the block by setting its status to **published**.

Only a published block is shown on the site. A draft block is kept, but a page that places it shows nothing in its place until the block is published.

## Place a block on a page

1. Open the page in the page designer (**Layout** on the page).
2. Drag **Gedeeld blok** (Shared block) from the palette into the grid.
3. Under **Shared block** in the inspector, choose the block. The list holds the published blocks of the portal's organisation.
4. Save and publish the page.

The block takes the size of the cell you gave it. Its own widgets are laid out inside that cell as you placed them in the block.

## Change a block everywhere

Open the block's layout, change it and save. The next time a visitor opens a page that places the block, on any portal of the organisation, it shows the new version. Saving the block clears the stored copies of the pages of every portal of the organisation, so no portal keeps showing the old one.

The block's page has a **Pagina's** tab that lists the published pages placing the block, with their portal and address. Look there before you change or remove a block, so you know where the change will show. A page that places the block only in a draft is not listed yet.

## What a visitor sees

- A published block of the portal's own organisation is shown in its cell.
- A block that is a draft, that was removed, or that belongs to another organisation shows nothing. The page is still served; the cell stays empty. A visitor cannot tell which of these was the reason.

## Who may do this

The groups set as page editors (**Administration settings**, then **Portaliq**, then **Page editors**) may create, change and delete shared blocks, as they may for pages. Administrators may always. Anyone else is refused, also when they call the register directly.
