# Proposal: hero-links-follow-the-site-address

## Why

Seen in the Zuiddrecht go-live rehearsal R2: the home page's "Veel gezocht" links read `/afval`, `/paspoort-en-id-kaart`. Under the app path (`/index.php/apps/portaliq/site?portal=zuiddrecht`) such an address is a page Nextcloud does not serve, so a new tab, a copied link or a click before the site loaded ended on a 404. site-links-follow-the-site-address made the menus and the footer follow the site address; the hero's popular links and actions, the portal counts, the featured subjects and the public detail's documents and secondary link still wrote the bare path.

## What changes

- `linkAddress(link, addressOf)` in `src/site/lib/shellData.js`: a site route in the site's own addressing, anything else as authored. The footer and the hero use it.
- The hero's popular links and actions carry the site address; a click still moves in place.
- `authoredLink()` keeps a link's query beside the route (`/zoeken?f.wooCategory=c1`), so the portal counts link to a filtered search that opens.
- The featured subjects and the public detail's documents and secondary link go through `authoredLink()`.

## Not changed

- A link to another site, `mailto:` and `tel:` stay as authored.
- Where a click goes: the route, as before.
