## Why

The Zuiddrecht example site is installed with one command and wears its theme, but held against
the approved boards (Kop, Home, Contentpagina, Voet, MobielHome) it still differs in what the
blocks can draw: the alert strip takes text only, the hero holds its search in a card and has no
room for "Veel gezocht", the task tiles draw their icons on a round ground and know eighteen fixed
shapes, the "Openbare informatie" card and the grey "Bestuur en organisatie" band cannot be
authored, a content page has no postcode form, and the breadcrumb prints the page's full title
where the board prints the menu's word. One of the differences is a bug: a browser that asks for
English gets an English document on a portal that declares only Dutch, so every date prints in
English.

## What Changes

- **`nlBanner`** gains a bold lead, a link after the text, the kind `notice` (the site's own
  soft attention colours, tokens) and `band` (edge to edge, text in the container).
- **The hero** gains `variant: plain` (the form on the band, input and button joined, no card)
  and `popularLabel` + `popularLinks` ("Veel gezocht"). The card variant is untouched.
- **`nlQuickTasks`** gains `iconStyle: plain` (a bare stroke in the accent), an `iconPath` per
  item (the author's own path on the 24 grid, held to path data), and a phone list (`narrow:
  list`, `narrowHeading`, `narrowLimit`).
- **`nlNewsList`** gains `leadPlaceholder`: words in the photo's place while the lead has none.
- **`nlLinkList`** gains `display: card | accent` and an `intro`. **`nlParagraph`** gains `lead`.
  **`nlTable`** gains `display: boxed` and `captionVisible`. **`nlButtonLink`** gains
  `icon: chevron`.
- **Two new blocks**: `nlLinkColumns` (a heading over columns of links on a band of its own) and
  `nlLookupForm` (a few fields in one row and a button that opens a page with the answers in its
  address, a GET form).
- **The breadcrumb** reads the header menu's own words for a route the menu names.
- **The document language follows the portal**: `Accept-Language` is held against the portal's
  declared `locales`; a portal that declares only `nl` serves `nl`.
- **Two optional tokens** read in `css/site-theme.css`: `--nldesign-website-page-title-size` (and
  `-line-height`) for the h1 of a content page, and `--nldesign-website-hero-decoration-opacity`,
  `--nldesign-website-hero-title-size` for the plain hero. Without them nothing changes.
- **The Zuiddrecht declaration** uses all of this: the home and the content page now follow the
  boards block for block.

Every change is additive: a placement that names none of the new props renders as before, which
`tests/site-pixel-match.spec.mjs` holds for the school portals' fixtures and the shipped demo.

## Depends on

- example-site-zuiddrecht (archived in the register as the Zuiddrecht declaration).
- thematiq zuiddrecht: the token values named in the PR body; the site renders without them.
