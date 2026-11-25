## ADDED Requirements

### Requirement: A block link to a site page carries the site's own address

A link a page block draws to a page of the site (a path starting with one `/`) MUST carry the address that opens that page in the site's addressing, as the menus and the footer do: the hero's popular links and actions, the portal counts, the featured subjects, and the public detail's documents and secondary link. A query on the link MUST stay on the address beside the route. A click MUST still move to the route in place. A link to another site, a `mailto:` or a `tel:` address MUST stay as authored.

#### Scenario: Veel gezocht under the app path
- GIVEN the site is served at `/index.php/apps/portaliq/site?portal=zuiddrecht`
- WHEN the home page's hero shows "Grof afval" for `/afval`
- THEN its link is `/index.php/apps/portaliq/site?portal=zuiddrecht&route=%2Fafval`
- @e2e exclude pinned by `tests/site-shell-blocks.spec.mjs` "the hero writes its popular links and actions in the site address"

#### Scenario: A count links to a filtered search
- GIVEN a portal count links to `/zoeken?f.wooCategory=c1`
- WHEN the site is served under the app path
- THEN the link carries `route=/zoeken` and `f.wooCategory=c1`
- @e2e exclude pinned by `tests/site-shell-blocks.spec.mjs` "a block link with a query keeps the query beside the route in the site address"
