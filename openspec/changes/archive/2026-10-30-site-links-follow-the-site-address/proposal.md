# Proposal: site-links-follow-the-site-address

## Why

The portal's menus and footer wrote their links as root paths (`/zoeken`). That address only opens the page when the portal has its own domain and a proxy rewrites short addresses to the site. Served under the app path (`/index.php/apps/portaliq/site?portal=zuiddrecht`), a new tab, a copied link or a page whose script failed opened Nextcloud's root instead. Seen in the Zuiddrecht go-live rehearsal on 2026-10-15. The resident menu and the breadcrumb already used the site's own address.

## What changes

- A header menu item, its children, and the items of the menu block get an `href` in the site's own addressing (`addressOfRoute`): the short address on a portal domain, the long address with `?portal=` and `?route=` anywhere else.
- The footer writes its column links, legal links, button and contact links the same way.
- A click still moves to the item's route in place. Links to another site, `mailto:` and `tel:` stay as they are.

## Not changed

- How the site decides its addressing (`addressing.root` from the server, the long address in the address bar).
- The menus the content API returns.
