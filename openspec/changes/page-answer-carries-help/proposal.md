# page-answer-carries-help

## Why

help-texts-and-form-help added a page's `helpText` to the pages LIST the content API answers, but not to the single
page answer (`/api/content/page`), which is what the site reads to draw a page. So "Hulp bij deze pagina" never showed on
any page, whatever its help text (PtStemgedrag, pq3).

## What changes

- The single page answer carries `helpText`, shaped as the list does (`PortalHelp::pageText`), so the site draws the
  disclosure under the page's intro.
