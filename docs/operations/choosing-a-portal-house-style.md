---
title: Choosing a portal's house style
sidebar_label: Choosing a house style
description: How an administrator picks a portal's house style from the theme app, and what the readability check says
---

# Choosing a portal's house style

A portal wears one house style: a token set from the theme app (thematiq). The administrator picks it on the portal's own page, in the **House style** widget, instead of typing its name.

## What the widget lists

Only the sets the portal can actually render: listed in the theme app's catalogue and with a token file installed. Each shows a readability verdict, worked out by the theme app's own contrast check on the surfaces a portal paints from the set's tokens (the page background and the footer):

- **Readable**: every text colour it could measure meets WCAG AA (4.5:1).
- **Hard to read**: at least one text colour does not.
- **Not checked**: the set declares no token for those surfaces, or the theme app could not be asked. This is never shown as readable.

When the portal names a set the theme app does not offer, the widget says so: that portal shows without a house style until another one is chosen.

## Saving

Choose a set and press **Save**. A readable or unchecked set is saved straight away: "The house style is saved."

A hard-to-read set is not saved at first. The widget lists each failing token with its ratio, for example "--nldesign-footer-legal-color on the footer is 1.06:1, and needs 4.5:1." **Use it anyway** saves it. Fix the tokens in the theme app instead when you can.

## Who may do this

Administrators only. The widget reads and saves through `GET` and `PUT /apps/portaliq/api/portals/{slug}/theme`, which carry no opt-out attribute, so Nextcloud refuses anyone else. The catalogue is never served to anonymous visitors.
