## ADDED Requirements

### Requirement: A banner may carry a lead and a link

`nlBanner` MUST render an authored `lead` in bold before the text and, when `linkLabel` has words
and `linkHref` is a path inside the site or a web, mail or phone address, a link after the text; a
plain click on a path inside the site MUST stay in the site. The kind `notice` MUST colour the
strip from `--nldesign-website-notice-*` tokens, falling back to the warning alert's. With `band`
the strip MUST paint edge to edge with its text in the page's container, and the widget grid MUST
emit such a banner as a band. A banner that names none of this MUST render as before.

#### Scenario: The Zuiddrecht "Let op" strip
@e2e exclude held in tests/site-pixel-match.spec.mjs; live screenshot by the coordinator
- GIVEN a banner with lead "Let op", a text, link "Bekijk de openingstijden" to `/contact`, kind `notice` and `band`
- WHEN the home page renders
- THEN the strip runs edge to edge in the notice colours with "Let op" bold, the text and the link, and no close button

#### Scenario: A school's banner
- GIVEN a banner placed with only `kind`, `text` and `closable`
- WHEN the page renders
- THEN it renders inside its grid cell, as it did before

### Requirement: The hero may draw its search plain with popular links

The hero MUST accept `variant: plain`, drawing the heading large and the search form on the band
itself with input and button joined and no card, and `popularLabel` with `popularLinks`: the links
with a label and a followable address, six at most, under the form; a plain click on a path inside
the site MUST stay in the site. The default variant MUST keep the card.

#### Scenario: Zuiddrecht home
@e2e exclude held in tests/site-pixel-match.spec.mjs; live screenshot by the coordinator
- GIVEN the hero with `variant: plain` and four popular links
- WHEN the home page renders
- THEN "Wat wilt u regelen?" is followed by the form and "Veel gezocht:" with four links

#### Scenario: A school's hero
- GIVEN a hero without `variant`
- WHEN the page renders
- THEN the search sits in its card, as before

### Requirement: The task list may draw bare icons and a phone list

`nlQuickTasks` MUST accept `iconStyle: plain` (the icon as a bare stroke in the accent, no round
ground) and, per item, `iconPath`: a path on the 24 grid held to path data (commands, numbers,
spaces, commas, dots and minus signs, at most 400 characters), which MUST win over a named icon and
MUST be dropped otherwise. With `narrow: list` a phone MUST draw the tiles as bare rows without
icons or card under `narrowHeading`, the first `narrowLimit` rows only; a wide screen MUST keep the
card. Without these props nothing changes.

#### Scenario: Zuiddrecht "Direct regelen"
@e2e exclude held in tests/site-pixel-match.spec.mjs; live screenshot by the coordinator
- GIVEN eight tasks with their own `iconPath`, `iconStyle: plain`, three columns, `narrow: list`, `narrowHeading` "Veel gezocht" and `narrowLimit` 6
- WHEN the home page renders at 1440 and at 390 pixels
- THEN the wide page shows the card with red strokes in three columns, and the phone shows "Veel gezocht" over six bare rows

#### Scenario: A path that is not path data
- GIVEN an item whose `iconPath` holds a `<` or a letter outside the path commands
- WHEN the tiles are computed
- THEN that item draws its named icon, or none

### Requirement: A link list may draw as a card or under an accent line

`nlLinkList` MUST accept `display: card` (a bordered, rounded card with bold links) and
`display: accent` (a thick line in the accent above, room under it), and an `intro` line between
the heading and the links. `plain`, the default, MUST render as before.

#### Scenario: "Openbare informatie" and "Meer over afval"
@e2e exclude held in tests/site-pixel-match.spec.mjs; live screenshot by the coordinator
- GIVEN the home's "Openbare informatie" with `display: card` and an intro, and the content page's "Meer over afval" with `display: accent`
- WHEN the pages render
- THEN the first is a bordered card reading heading, intro, link; the second has a red line above its heading

### Requirement: Link columns draw a heading over columns of links on a band

`nlLinkColumns` MUST render a heading over at most four columns, each a `nav` with its own heading
and a list of links with a label and a followable address, on a band that paints edge to edge
(`tone: surface` on the set's surface colour, `tone: plain` on the page's own) with the content in
the page's container. The widget grid MUST emit it as a band. A column without a usable link MUST
be left out.

#### Scenario: "Bestuur en organisatie"
@e2e exclude held in tests/site-pixel-match.spec.mjs; live screenshot by the coordinator
- GIVEN three columns of three links on `tone: surface`
- WHEN the home page renders
- THEN a grey band runs edge to edge with the heading and three columns inside the container

### Requirement: A lookup form opens a page with its answers in the address

`nlLookupForm` MUST render at most four text fields in one row, each with a visible label, and a
button, as a GET form whose action is the authored page when that is a path inside the site or a
web address; a submit on a path inside the site MUST stay in the site and carry the fields as query
parameters. A field without a name or a label MUST be left out; a width MUST be in rem or ch.
With an address the site cannot follow, no form MUST render.

#### Scenario: The postcode form
@e2e exclude held in tests/site-pixel-match.spec.mjs; live screenshot by the coordinator
- GIVEN fields `postcode` and `huisnummer` and the button "Toon mijn afvalkalender" to `/afval`
- WHEN a visitor submits
- THEN the site opens `/afval?postcode=…&huisnummer=…` without leaving the document

### Requirement: Other blocks gain one drawn option each

`nlParagraph` MUST accept `lead` (Utrecht's lead paragraph); `nlTable` MUST accept
`display: boxed` (a bordered, rounded box with a tinted header row) and `captionVisible` (off keeps
the caption for screen readers only); `nlButtonLink` MUST accept `icon: chevron` (an arrow after
the words); `nlNewsList` MUST accept `leadPlaceholder` (words in the photo's place while the lead
has no photo). Each default MUST render as before.

#### Scenario: The content page
@e2e exclude held in tests/site-pixel-match.spec.mjs; live screenshot by the coordinator
- GIVEN the content page with a lead paragraph, a boxed table without visible caption and a chevron button
- WHEN it renders
- THEN the intro is larger, the table is a bordered box with a grey header row and the button carries an arrow

### Requirement: A breadcrumb reads like the menu

For a crumb whose route the header menu names, the breadcrumb MUST print that menu item's name
instead of the page's title or the humanised path segment.

#### Scenario: The content page
- GIVEN the header menu names `/afval` "Afval" and the page there is titled "Afval scheiden en ophalen"
- WHEN the breadcrumb is computed
- THEN it reads "Home › Afval"

### Requirement: The document language follows the portal

The site's document language MUST be the visitor's `Accept-Language` only when the serving portal
declares no `locales` or declares a locale with the same language; otherwise it MUST be the
portal's first declared locale. With no header the language MUST stay `nl`.

#### Scenario: A browser asking for English on a Dutch portal
- GIVEN a portal with `locales: ["nl"]` and a request with `Accept-Language: en-US`
- WHEN the site renders
- THEN `<html lang="nl">`, and the news dates print in Dutch

### Requirement: The Zuiddrecht declaration follows the boards

The Zuiddrecht home and content page MUST be declared with the blocks and options above so that
they render as the boards Home, Contentpagina and MobielHome draw them, and every page reachable
before this change MUST stay reachable.

#### Scenario: The declaration
- GIVEN `lib/Settings/sites/zuiddrecht.json`
- WHEN it is held against the schemas and the blocks
- THEN every option it names exists on the block it names, every link points at a page of the site, and the set of routes is unchanged

### Requirement: The install offer is a dialog over the page that remembers "Not now"

The site's install offer MUST render as a modal dialog over the page (`role="dialog"`,
`aria-modal="true"`, a scrim, fixed position, never in the document flow), with the focus moved
into it on open and held inside it, Escape and the scrim answering "Not now". "Not now" MUST be
remembered in the browser for thirty days, so the offer does not return on every page; without
storage it MUST behave as before. A browser that makes no offer MUST still see nothing.

#### Scenario: The Zuiddrecht demo
@e2e exclude held in tests/install-banner.spec.mjs; seen live by the coordinator
- GIVEN Chrome offers to install the site
- WHEN the home page opens
- THEN the page does not move and a centred dialog asks; "Niet nu" closes it and it stays away on the next page
