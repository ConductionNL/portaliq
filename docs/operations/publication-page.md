---
title: The publication page every portal needs
sidebar_label: Publication page
description: Why each portal needs a page at /publicatie, and how to add it, so links to a publication never end on page not found
---

# The publication page every portal needs

Several places in the portal link to a single publication:

- a search result on the site,
- the notice a resident gets when a saved search finds something new,
- the notice a resident gets when the decision on their Woo request is published.

Each of those links opens `/publicatie/<id>` on the portal's site. The site shows that address with the portal's own page at `/publicatie` and fills in the publication with the given id.

Portaliq does not create that page for you. A portal without a page at `/publicatie` answers every one of those links with "Pagina niet gevonden".

## Adding the page

Create one page per portal, with the route `/publicatie`, status `published` and the portal's id in `portal`. Give it a body with the publication block:

```json
{
  "route": "/publicatie",
  "title": "Publicatie",
  "portal": "<portal id>",
  "status": "published",
  "locale": "nl",
  "body": {
    "type": "grid",
    "widgets": [
      { "id": "publication", "slot": "body", "widgetKey": "publicationDetail", "gridX": 0, "gridY": 0, "gridWidth": 12, "gridHeight": 6 }
    ]
  }
}
```

Leave `props` out when it would be empty: the register refuses an empty `{}` there.

You can lay the page out like any other page and place more blocks around the publication, for example a search box or a short explanation.

## Checking it

Open a publication from a search result on the site. The page shows the title, summary, publication date, information category, themes and documents. If it says "Pagina niet gevonden", the portal has no page at `/publicatie` yet, or the page is not published.
