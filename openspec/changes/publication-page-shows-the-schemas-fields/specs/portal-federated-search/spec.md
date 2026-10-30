## MODIFIED Requirements

### Requirement: The publication page must show what a visitor needs, in words

The site's publication page MUST show the title, the summary (else the description, else the short description), the publication date in the site's language, the information category by its name, the themes by their names, and the documents. For a Woo publication (one that carries `wooCategory`, or whose schema describes a `wooCategory` property, since opencatalogi's `publication` schema requires only a title) it MUST NOT show the archive's bookkeeping: Plooi fields, retention fields, the depublication date, the organisation id, the status, the publication kind or any other stored code. A publication of another schema adds its other public fields as the next requirement describes. A row without a value MUST be left out. A theme whose name cannot be read MUST be left out rather than shown as its id. The search block's information category filter MUST name each category the same way. The 17 category names are the statutory list of Woo art. 3.3, in Dutch and English.

#### Scenario: A Woo decision reads in words
- GIVEN a publication with `wooCategory: infocat014`, a theme "Verkeer en parkeren", Plooi and retention fields and an organisation id
- WHEN a visitor opens its page on the site
- THEN the page shows the summary, "Publicatiedatum", "Informatiecategorie: Woo-verzoeken en -besluiten" and the theme's name
- AND no Plooi or retention label, no `infocat014` and no theme or organisation id appears
- @e2e exclude pinned by `tests/publication-documents.spec.mjs` ("the publication page renders the summary and the visitor rows, no internal field", "a Woo publication gets no extra rows: the page reads exactly as before (#1645)")

#### Scenario: A Woo publication without a category keeps its bookkeeping off the page
- GIVEN a publication of opencatalogi's `publication` schema without `wooCategory`, with `status`, `publicationKind`, `soortHandeling` and retention and Plooi fields, and its schema in `@self.schema`
- WHEN a visitor opens its page on the site
- THEN no row beyond the publication date and the themes appears: no status, publication kind, retention or Plooi label
- @e2e exclude pinned by `tests/publication-documents.spec.mjs` ("a Woo publication without a category is known by its schema and gets no extra rows (#1772 review)")

#### Scenario: The category filter names its categories
- GIVEN the search answers with category buckets `infocat014` and `infocat016`
- WHEN the filter column renders
- THEN the checkboxes read "Woo-verzoeken en -besluiten" and "Beschikkingen"
- @e2e exclude pinned by `tests/publication-documents.spec.mjs` ("the category filter shows names")

## ADDED Requirements

### Requirement: The publication page MUST show the public fields of any other schema

For a publication that is not a Woo publication as the requirement above defines it, the page MUST show, after the rows above, every other field the API returned as a row, so the schema in OpenRegister decides what a visitor reads: its property-level RBAC already strips what an anonymous reader may not see, and portaliq MUST NOT add a visibility rule of its own. The page MUST ask for the publication with `_extend=@self.schema`. When the schema comes along, each row MUST carry the property's `title`, the rows MUST follow the properties' `order` and then the schema's own order, a `date` or `date-time` MUST read the way the site's reader writes a date, an enum value with an `x-enum-labels` label MUST read as that label, and a `markdown` value MUST read as text lines: headings, paragraphs and list items, with a link, emphasis and code reading as their text. Without the schema the field's name MUST be put in words. The page MUST leave out a property marked `visible: false`, the long description and the summary fields included (the summary then falls through to the next of `summary`, `description`, `shortDescription`), an empty value, a file, and a related object known only by its id, whether the property itself or its items carry the `$ref`; without the schema nothing marks a relation, so a UUID MUST then be taken as a related object known only by its id and left out, also from a list. A related object it holds MUST read as its name or title, and a list as a list. `visible: false` can only be honoured when the schema comes along: without it such a property shows. A `longDescription` MUST show under the summary as such text lines. Neither it nor any other value may be rendered as HTML.

#### Scenario: A stackiq application shows its public fields
- GIVEN a published stackiq application whose schema is public, with an application type, a licence, a website, a hosting location and a long description, and an `externalId` the schema lets only signed-in users read
- WHEN an anonymous visitor opens its page on the site
- THEN the page shows the long description under the summary and a row per field, labelled with the schema's titles ("Application type", "License", "Website", "Hosting location"), the website as a link
- AND the page shows the short description as the summary, the application having no `summary` or `description`
- AND no `externalId` appears, because the API did not return it
- @e2e exclude pinned by `tests/publication-documents.spec.mjs` ("the fields of another schema read in the words and order of the schema, hidden and coded ones left out (#1645)", "the publication page asks for the schema and renders the fields of the other schema as text (#1645)")

#### Scenario: A hidden property and a bare relation stay off the page
- GIVEN the schema marks `type` `visible: false`, and `compliancy` holds only the id of a related object
- WHEN a visitor opens the page
- THEN neither "Type" nor the id appears
- @e2e exclude pinned by `tests/publication-documents.spec.mjs` ("the fields of another schema read in the words and order of the schema, hidden and coded ones left out (#1645)")

#### Scenario: A peer without the schema still shows the fields
- GIVEN the answer carries the schema's id instead of the schema
- WHEN a visitor opens the page
- THEN each field reads under its name in words, `applicationType` as "Application type"
- @e2e exclude pinned by `tests/publication-documents.spec.mjs` ("without its schema a field name reads in words, and a date the way the reader writes it (#1645)")
