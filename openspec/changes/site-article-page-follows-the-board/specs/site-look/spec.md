## ADDED Requirements

### Requirement: A news article reads like the article board

A page whose main region holds an `nlNewsArticle` MUST NOT print its own title as an h1. Once the
article is read, the breadcrumb MUST end on the article's title. When the block declares
`sectionHref`, the breadcrumb MUST read home, then that section (its `sectionLabel`, else the menu's
words for it), then the title, and the header menu MUST mark the item that links to the section. A
list in the body whose every item opens with a bold label MUST render as a block of labels and
values on the muted surface, not as bullets.

#### Scenario: The Kinderboekenweek article on De Wilgenboom
@e2e exclude Unit and render checks in node: tests/site-look/article-page.spec.mjs; live screenshot on :8092 in the PR
- GIVEN the `/nieuws` page with an `nlNewsArticle` declaring `sectionHref: "/zoeken"` and `sectionLabel: "Nieuws en documenten"`, and a menu item "Nieuws" linking to `/zoeken`
- WHEN a visitor opens `/nieuws/<id>` of "De Kinderboekenweek is begonnen"
- THEN the only h1 is "De Kinderboekenweek is begonnen"
- AND the breadcrumb reads "Home › Nieuws en documenten › De Kinderboekenweek is begonnen"
- AND "Nieuws" in the menu carries `aria-current="true"`
- AND "Wanneer", "Waar", "Meenemen" and "Helpen" show as labels beside their values in one grey block

#### Scenario: An article that is not found
@e2e exclude Unit check in node: tests/site-look/article-page.spec.mjs
- GIVEN an id that is no public item of the portal
- WHEN the article block has read it
- THEN it tells no subject, and the breadcrumb keeps the route's own words

### Requirement: Enter in a block's own search field searches that block

The widget grid MUST hand a block's `search` on to the page only when it is a search term. A native
`search` event from a field inside a block MUST NOT start a site search.

#### Scenario: Enter in the catalogue
@e2e exclude Unit check in node: tests/site-look/article-page.spec.mjs; live check on :8092 in the PR
- GIVEN the "Nieuws en documenten" page with its catalogue block
- WHEN a visitor types "ouderavond" in the block's field and presses Enter
- THEN the results read "... resultaten voor "ouderavond"", never "[object Event]"
