# Proposal: news-dialog-eyebrow

## Why

The PtNieuwsbericht board draws the news dialog as every staff dialog is drawn: a small line above the title that names
the list and, for an item that exists, its state ("Nieuws, concept"). The dialog had no such line, so its title sat
18px from the top instead of 41px and the form started 34px under it instead of 22px.

## What changes

- NewsItemDialog passes an eyebrow to CnDialog: "News" for a new item, "News, draft" or "News, published" for one that
  exists.
- Two new strings with their Dutch: "Nieuws, concept", "Nieuws, gepubliceerd".
