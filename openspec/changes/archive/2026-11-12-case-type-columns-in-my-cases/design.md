# Design: case-type-columns-in-my-cases

## Screen

Board PtZaaktypeWeergave, section "Kolommen in Mijn zaken": Veld (read-only), Weergave (Tekst, Link, QR-code, Datum), Tekst van de link, and "Zo ziet de inwoner het" with the code and the link text under it.

## Decisions

### D1: Settings live on the portal, keyed by case type

What a resident sees is a portal's choice (the same reason `hiddenCaseTypes` lives on the portal). A map by case type id keeps one portal's settings for one type in one place, and two portals of one organisation can differ.

### D2: The rows a field list offers come from the declared `cases` collections

The administrator picks how a field reads, not which fields exist. The fields are the columns the case apps declare on their `cases` collections, each once, so a setting can never name a field the app does not project. The loop over the contribution registry moved into `CasesCollections`, which the case type catalogue uses as well.

### D3: One more choice than the board: "Zoals de zaakapp het toont"

The board's four kinds would force a setting on every field. An empty first choice means no setting: the field reads as its case app declares, and only the fields an administrator changed are stored.

### D4: The server stamps, the site reads

`ColumnOverrides` adds `_columns` (keyed by field) to each case row whose type has settings, in the same pass that already decides which rows the serving portal shows. The site treats `_columns` as envelope and lets `rowColumn()` replace `render` and `linkLabel` per row. The record page reads the same stamp through `screenRow`.

### D5: Saving one type at a time

The admin section saves the case type it shows. The write merges into the stored portal inside `PortalObjectWriter::updateObject`'s callable, so a save of one type cannot lose another type saved a moment earlier. No type left stores `{}`.

## Risks

- The preview code draws this portal's Mijn zaken address, not a real case value: the administrator sees the shape and the words, not a resident's data.
