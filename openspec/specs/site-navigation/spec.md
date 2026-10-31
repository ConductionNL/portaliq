# site-navigation Specification

## Purpose
Links on a portal site: links to a record or a search result keep the portal and the route, and menu links to a site page carry the site's own address.

## Requirements

### Requirement: A link to a record or a search result keeps the portal and the route (REQ-RSL-001)
A link the site writes to one record on its list page (`record`), to a search result and to an intake form MUST be built in the addressing of the host (`addressOfRoute`): the `portal` and `lang` of the current address MUST stay, and a record link MUST keep the route of the page it is listed on.

#### Scenario: A council meeting from the list on a shared host
@e2e exclude Node: tests/record-and-search-links.spec.mjs
- GIVEN the Zuiddrecht site is served as `?portal=zuiddrecht&route=/bestuur/vergaderingen`
- WHEN a visitor opens a meeting from the list
- THEN the link carries `portal=zuiddrecht`, `route=/bestuur/vergaderingen` and `record=<id>`

#### Scenario: A search result on a host that serves the site at its root
@e2e exclude Node: tests/record-and-search-links.spec.mjs
- GIVEN the site is served at the root of its host
- WHEN a visitor opens a search result
- THEN the link is the short address of the result's page

### Requirement: A menu link to a site page carries the site's own address

A link in the header menu, the menu block or the footer that names a page of the site (a path starting with one `/`) MUST carry the address that opens that page in the site's addressing: the short address on a host that serves the site at its root, the long address with the portal and the route anywhere else. A click MUST still move to the route in place. A link to another site, a `mailto:` or a `tel:` address MUST stay as authored.

#### Scenario: The site under the app path
- GIVEN the site is served at `/index.php/apps/portaliq/site?portal=zuiddrecht`
- WHEN the header menu shows "Woo-publicaties" for `/zoeken`
- THEN its link is `/index.php/apps/portaliq/site?portal=zuiddrecht&route=%2Fzoeken`
- @e2e exclude pinned by `tests/site-shell-blocks.spec.mjs` "under the app path a menu link carries the site address; its route stays the click target"

#### Scenario: The site on its own domain
- GIVEN the host serves the site at its root
- WHEN the header menu shows `/zoeken`
- THEN its link is `/zoeken` on that host
- @e2e exclude pinned by `tests/site-shell-blocks.spec.mjs` "on a portal domain the menu link stays the short address"

#### Scenario: The footer
- GIVEN the site is served under the app path
- WHEN the footer shows a column link, a legal link, its button or a contact link to a site page
- THEN each carries the long address, and a link to another site stays as authored
- @e2e exclude pinned by `tests/site-shell-blocks.spec.mjs` "the site menu and the footer write a site route in the site address"
