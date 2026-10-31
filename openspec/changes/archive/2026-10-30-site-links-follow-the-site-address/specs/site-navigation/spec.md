## ADDED Requirements

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
