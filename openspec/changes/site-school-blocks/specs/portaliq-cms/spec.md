## ADDED Requirements

### Requirement: A news item shows on a portal's public website only when staff put it there

A `newsItem` MUST be served to the public website of a portal only when its status is `published`, its `public` flag is the boolean `true` and its `portal` equals that portal's slug. An item whose target names single children MUST NOT be served publicly, whatever its flag says. A public read MUST return only the id, title, a plain-text intro, the publication moment, the staff-written `audienceLabel` and an image; it MUST NOT return the target, the author, the read receipts or the translations. An image MUST be served only when it is a published item of the same portal's media library. The read MUST apply the portal's declared sign-in modes like every other content read, and MUST be publicly cacheable only for a caller without a bearer. Updating an item without naming `public` MUST leave its website choice unchanged.

#### Scenario: A whole-school item on the website
- GIVEN a published `newsItem` with `public: true`, `portal: "wilgenboom"` and `audienceLabel: "hele school"`
- WHEN a visitor who is not signed in asks `/api/content/news?portal=wilgenboom`
- THEN the item is in the list with its title, intro, date and "hele school"
- AND the response carries no target, author or read receipts

#### Scenario: An item about one child stays off the website
- GIVEN a published, public item for `wilgenboom` whose target names a child
- WHEN the website's news is read
- THEN the item is not in the list and its own address answers 404

#### Scenario: Another portal's item is not this portal's news
- GIVEN a public item for `vaartveld`
- WHEN `wilgenboom`'s news is read
- THEN the item is not in it

#### Scenario: A photo from outside the library is not shown
- GIVEN a public item whose only photo is an address on another site
- WHEN it is read publicly
- THEN its image is empty

#### Scenario: Editing the text keeps the item on the website
- GIVEN a public item
- WHEN staff save new text through a screen that does not send `public`
- THEN the item is still public on the same portal

### Requirement: A news list shows the news staff put on the website

The `nlNewsList` widget MUST show the newest public items of the serving portal, at most its `limit` (1 to 12). With `featured` on and the `list` display, the newest item MUST lead with its photo, its date and audience, its title as a link and its intro; the others MUST be rows of date (and audience when `showAudience` is on) and title. The `compact` display MUST show rows only. An item MUST link to `<articleRoute>/<id>`. On a page whose route names an item, that item MUST be left out. Which portal is read MUST come from the host, not from the placement.

#### Scenario: The home page's news
- GIVEN three public items for the portal and a news list with `limit: 4`
- WHEN the home page renders
- THEN the newest item leads and the other two are rows
- AND each links to `/nieuws/<id>`

#### Scenario: Related news beside an article
- GIVEN the article page for item `n1` with a compact news list beside it
- WHEN it renders
- THEN `n1` is not in the list

### Requirement: A news article page shows one public item chosen by the route

The `nlNewsArticle` widget MUST show the public item whose id is the trailing route segment handed over by the host, with its date and audience, its title as the page heading, its first paragraph as the lead, its photo and the rest of its body through the site's sanitising markdown renderer. An id that is not a public item of the serving portal MUST read as not found, with a link back, never as an error. A placement MUST NOT be able to pin the widget to one item.

#### Scenario: An item that is not public
- GIVEN an id of a draft item
- WHEN `/nieuws/<id>` is opened
- THEN the page says the item does not exist or is no longer on the website, and links back to the news

### Requirement: A task list shows a portal's most asked tasks as tiles

The `nlQuickTasks` widget MUST render its authored items as at most twelve tiles in one card, each tile ONE link holding a decorative icon, the label and an arrow. A tile without a label, or whose address is not a path inside the site or an `http(s)`, `mailto` or `tel` address, MUST be left out. A path inside the site MUST become the site's own address for that route, and a plain click on it MUST stay in the site. `columns` MUST be 2, 3 or 4 on a wide screen; a phone MUST show one column. With `overlap` the card MUST be pulled up over the band above it.

#### Scenario: Eight tasks in three columns
- GIVEN eight items and `columns: 3`
- WHEN the home page renders at 1440px
- THEN the card shows the eight tiles in three columns, each one link

#### Scenario: A script address is refused
- GIVEN an item whose address is `javascript:alert(1)`
- WHEN the card renders
- THEN that item is not a tile

### Requirement: A dated list shows each date as a tile or a label

The `nlEventList` widget MUST render its items sorted by date, each with a date tile (`display: tiles`, the day and the short month in the page language, the full date for assistive technology) or a date label (`display: labels`, the authored `dateLabel` or the day or run of days), the title (a link when the item has a usable address), a meta line and a note whose tone (`neutral`, `positive`, `warning`) only adds weight to words. With `upcomingOnly` on, an item whose last day is before today MUST be left out. An item without a title or a readable date MUST be left out.

#### Scenario: This month at school
- GIVEN items on 1, 7, 9 and 29 October and today is 5 October
- WHEN the list renders with tiles
- THEN it shows 7, 9 and 29 October in that order, each as a tile

#### Scenario: A run of days as a label
- GIVEN an item from 17 to 25 October without a `dateLabel`
- WHEN the list renders with labels
- THEN its label reads "17 - 25 okt"

### Requirement: The sign-in card offers the portal's own ways in

The host MUST hand `nlSignIn` the portal's own sign-in routes and whether a session is held, after the authored props, so a placement cannot add or change a way in. A way whose address is not a path inside the site MUST be refused. With `display: card` the widget MUST show the heading, intro, the authored points as a check list, one button and a note. Signed out with one way in, the button MUST go straight to it; with several or none known, it MUST go to the sign-in page. Signed in, it MUST lead to the visitor's own area.

#### Scenario: One way in
- GIVEN a portal whose only mode is DigiD and a sign-in card
- WHEN a visitor who is not signed in sees the card
- THEN its button starts the DigiD sign-in

#### Scenario: A placement cannot add a way in
- GIVEN a placement that declares its own `ways` with an address on another site
- WHEN the card renders
- THEN only the portal's own ways are offered

### Requirement: A list may show numbered steps with a title and a line

The `nlList` widget MUST accept lines as text or as `{title, text}`. With `display: steps` it MUST render an ordered list whose numbers are drawn large and whose items show the title and the line.

#### Scenario: Three steps
- GIVEN three lines with a title and a text and `display: steps`
- WHEN the list renders
- THEN it is an ordered list of three, each with its number, title and line
