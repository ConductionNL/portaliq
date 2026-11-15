## ADDED Requirements

### Requirement: A date inside page content must read in the content language

The site shell MUST provide the language of the page on screen (the page record's `locale`, else
the site's language) to its blocks and set it as the page's `lang`. The news list, the news
article, the event list and the catalogue MUST format their dates in that language. Without the
shell, the document's language MUST apply.

#### Scenario: A Dutch page in an English browser
@e2e exclude Rendered in node with an English document: tests/site-look/content-dates.spec.mjs
- GIVEN the wilgenboom home page (locale `nl`) on a portal that declares `nl` and `en`, opened by a browser that asks for English
- WHEN "Deze maand op school" and "Nieuws van school" render
- THEN the tiles read "7 okt" and the news rows "2 oktober 2026"

#### Scenario: A page outside the shell
@e2e exclude Rendered in node: tests/site-look/content-dates.spec.mjs
- GIVEN an event list rendered without the site shell in an English document
- WHEN it renders
- THEN its tiles read "Oct"
