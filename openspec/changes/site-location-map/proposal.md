# Proposal: site-location-map

## Why

The board [portaliq/LocatiesKaart](https://identity.conduction.nl/screens/board?id=portaliq/LocatiesKaart) shows the organisation's places on a page "Locaties": chips per kind of place, a map with numbered markers, and the same numbers in a list with address, opening hours, a route link and the place's own links. The site has no block that draws places on a map.

## What Changes

- A site block `nlLocationMap` (group Inhoud): authored places with name, address, opening line, kind, coordinates and links; chips per kind; a sketch map drawn from the coordinates with numbered markers and zoom buttons; the numbered list beside it with "Route" opening OpenStreetMap's route planner in a new window.
- The sketch loads nothing from another site, so no map service sees the visitor and the site's content security policy stays as it is. The list carries every fact, so the map is never the only way to a place.
- The Zuiddrecht example site gets `/contact/locaties`, linked from Contact.

## Not in this change

A tiled map with streets needs a tile provider and an image source in the site's content security policy; that is a decision of its own (PDOK's BRT background map is the obvious Dutch government candidate).
