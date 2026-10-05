## Why

A fresh portaliq shows one portal, "Open Catalogi", with a hero and a search page. The portal we
show in demonstrations is hand-made on one machine and wears the VNG house style, which nobody can
install and which reads as somebody else's website. The Zuiddrecht design is approved: a
municipality that does not exist, with its own theme in thematiq (`zuiddrecht`). An administrator
has to be able to put that website on any instance with one command, and take it off again.

## What Changes

- **A site declaration**, `lib/Settings/sites/zuiddrecht.json`: the portal record (theme
  `zuiddrecht`, header search, the "Mijn Zuiddrecht" button, the footer with its button and contact
  column), the main menu and two footer menus, 33 pages (home, the content page "Afval scheiden en
  ophalen", a page behind every link) and four public news items. Copy and layout of the header,
  footer, home and content page follow the design files; the other pages are short new copy so no
  link is dead.
- **`occ portaliq:example-site:install <site>`** writes what is missing and never changes what is
  there. After writing it reads the instance back, counts what arrived per type against what the
  declaration holds, and names every declared key the stored object lost. It exits 2 when anything
  is missing.
- **`occ portaliq:example-site:remove <site>`** deletes exactly the objects the install created,
  from its own record, and nothing else. A portal that was there before, or that has gained content
  since, stays.
- **The current menu item can sit in the line under the menu**: two optional theme tokens,
  `--nldesign-website-nav-current-color` and `--nldesign-website-nav-current-in-line`, read in
  `css/site-theme.css`. Without them the item keeps the bar it has.
- **Links in `nlLinkList` and `nlButtonLink`** to a page of the site now open that page wherever
  the site is served, also through Nextcloud (`?portal=`), like the newer blocks do.

## Depends on

- `site-chrome-follows-the-design` (header search, account button, footer button and contact
  column) and `site-school-blocks` (`nlQuickTasks`, `nlNewsList`, `nlNewsArticle`, the `nlSignIn`
  card): both are merged into this branch.
- thematiq `brand-motif-on-portals` for the role layer, and a thematiq follow-up for the two
  current-item tokens and the light hero on the `zuiddrecht` set.

## Out of scope

The Woo search and publication pages as designed, messages, dossiers, the editor view and phone
polish. `/zoeken` and `/publicatie` hold the search and detail blocks the shipped demo portal
already places.
