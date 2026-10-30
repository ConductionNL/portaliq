# Proposal: collection-intro

## Why

Dossiq adds an `intro` on its contributed `mijnZaken` collection: an admin-set text shown above "Mijn zaken". Portaliq read `intro` only for themes, CMS pages and help, so a collection's intro was carried through and never shown.

## What changes

- The contribution contract documents `intro` on a collection: optional plain text shown above the collection's list. The normaliser keeps it, trimmed, only when it is a non-empty string, and drops anything else.
- The site's collection renderer (`ContributionPage.vue`) shows it under the collection's heading, above its table.
- "My cases" (`MyCasesPage.vue`) shows the intros of the `kind: cases` collections under its heading, once each.
- Both put the text in a text node, as the theme intro does, so markup in it shows as typed. It is never rendered as HTML.

## Not changed

- The collection endpoint and the row shape: the intro is presentation only.
- Any app's collection can carry an intro; nothing is specific to dossiq.
