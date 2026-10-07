# portal-federated-search Specification

## Purpose
A visitor searches the publications of every catalogue the portal federates and opens one to read it. This spec covers what that visitor sees: results and a publication page in words, with category and theme names instead of the ids and archive bookkeeping the catalogue stores. The search itself is answered by opencatalogi's federated endpoint; portaliq only reads it and draws the page.

## Requirements

### Requirement: The publication page must show what a visitor needs, in words

The site's publication page MUST show the title, the summary (else the description), the publication date in the site's language, the information category by its name, the themes by their names, and the documents. It MUST NOT show the archive's bookkeeping: Plooi fields, retention fields, the depublication date, the organisation id, the status, the publication kind or any other stored code. A row without a value MUST be left out. A theme whose name cannot be read MUST be left out rather than shown as its id. The search block's information category filter MUST name each category the same way. The 17 category names are the statutory list of Woo art. 3.3, in Dutch and English.

#### Scenario: A Woo decision reads in words
- GIVEN a publication with `wooCategory: infocat014`, a theme "Verkeer en parkeren", Plooi and retention fields and an organisation id
- WHEN a visitor opens its page on the site
- THEN the page shows the summary, "Publicatiedatum", "Informatiecategorie: Woo-verzoeken en -besluiten" and the theme's name
- AND no Plooi or retention label, no `infocat014` and no theme or organisation id appears
- @e2e exclude pinned by `tests/publication-documents.spec.mjs` ("the publication page renders the summary and the visitor rows, no internal field")

#### Scenario: The category filter names its categories
- GIVEN the search answers with category buckets `infocat014` and `infocat016`
- WHEN the filter column renders
- THEN the checkboxes read "Woo-verzoeken en -besluiten" and "Beschikkingen"
- @e2e exclude pinned by `tests/publication-documents.spec.mjs` ("the category filter shows names")
