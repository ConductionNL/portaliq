# Proposal: site-school-blocks

## Why

Four school portals were designed on 5 October 2026 (De Wilgenboom, Vaartveld College, Esdoornveen, Warmtepompacademie; boards in the school-design canvases, plan `PORTAL-PLAN.md` gaps G-09, G-10, G-11, G-13, G-16). Their home pages are built from the same handful of blocks, and the page palette cannot draw any of them today:

- **"Direct regelen"**: one card of 8 or 9 task tiles (icon, label, arrow), pulled up over the hero. The palette has `nlLinkList` (a plain list) and the library's `cardGrid` (cards, not tiles). Quick tiles exist only on contributed pages (`QuickTiles.vue`), fed by `cta` blocks.
- **News**: the newest item as a card with photo and intro, then rows of date and title, "Al het nieuws". portaliq's `newsItem` is for an audience only: it is read authenticated, has no portal, and no endpoint serves it to a visitor who is not signed in. There is no news widget for a CMS page.
- **A dated list**: "Deze maand op school" with date tiles, "Agenda" with date labels ("13 en 15 okt"), "Eerstvolgende cursusdagen" with a places note. Nothing on a CMS page draws a date tile.
- **The "Mijn ..." card**: heading, intro, a check list, one button, a note. `nlSignIn` draws a row of buttons and is handed no ways in at all: `WidgetGrid.propsFor()` has no branch for it, so on every portal it says "Dit portaal heeft nog geen manier om in te loggen."
- **Numbered steps**: "Kies de cursus", "Schrijf in", "Het certificaat staat klaar". `nlList` takes strings only.
- **The news article** (Artikel board) and its "related news" aside.

Everything must stay generic: no school names, no colours, no education words in portaliq. The look comes from the thematiq token sets.

## What changes

- **`newsItem` 0.4.0** (register 0.59.0): `public` (boolean, default false), `portal` (slug) and `audienceLabel` (the words the website shows, such as "hele school"). The staff `create` and `update` endpoints accept them; `update` without `public` leaves the choice as it was.
- **`GET /api/content/news`** and **`GET /api/content/news/{id}`** (`ContentNewsController`, `PublicNewsReader`): published items staff put on THIS portal, newest first. Never an item that targets single children, never a photo that is not a published item of the portal's own media library, never the target, author, receipts or translations. Gated by the portal's sign-in modes like the rest of `/api/content`.
- **Four new widgets**, each loaded on demand: `nlQuickTasks`, `nlNewsList` (with a `compact` display for an aside), `nlNewsArticle` (item from the route), `nlEventList` (tiles or labels; items authored on the page in this change).
- **`nlSignIn`**: a `card` display, and the host now hands it the portal's own sign-in routes and the signed-in state, after the authored props.
- **`nlList`**: lines may be `{title, text}`, and `display: steps` draws large numbers.
- A shared `DateTile` part and date helpers under `src/site/components/mijn/`, for this change and the Mijn omgeving displays that follow.
- The coverage record gains `SITE_COMPOSITIONS`: widgets made of several NL Design System components, kept out of the 101-row record that mirrors design D1.

## Not in this change

- An app filling `nlEventList` (an anonymous contribution of events, plan G-12). The item shape is fixed here so that change only adds a `source`.
- Search over news and documents (G-14).
- The staff News screen's own checkbox for `public`; the endpoint accepts it, the example sets write it.
- The course card inside the warmtepompacademie hero: the hero belongs to the site chrome.

## Depends on

- The shell passing `signInRoutes` into `WidgetGrid` (one line in `App.vue`, owned by the chrome lane). Until it lands, the card's button goes to the sign-in page instead of straight to the one way in.
