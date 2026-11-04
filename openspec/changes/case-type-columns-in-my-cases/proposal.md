# Proposal: case-type-columns-in-my-cases

## Why

The board PtZaaktypeWeergave draws a section "Kolommen in Mijn zaken" on a case type's portal settings: per column the administrator chooses how the value reads (Tekst, Link, QR-code, Datum), gives a link or code its own words ("Toevoegen aan wallet"), and sees what the resident sees, with a QR code preview. The column render `qr` and `linkLabel` exist since `link-field-qr-code`, but only the case app can set them, for every portal and every case type at once. A municipality that wants the permit proof of one case type as a wallet code in its own portal has no way to say so.

## What changes

- **A portal stores column settings per case type.** `portal.caseTypeColumns` maps a case type id to a list of `{field, render, linkLabel}`; `render` is `text`, `link`, `qr` or `date`, and `linkLabel` (at most 60 characters) is kept on a link or QR code only. Register 0.107.0, portal 0.28.0.
- **The admin saves one case type at a time.** `PUT /api/portals/{slug}/case-types/{typeId}/columns` (admin-only) replaces that type's settings and leaves the others; an empty list goes back to how the case app declares the columns. The list route also answers the stored settings and the fields the case apps show in Mijn zaken.
- **A case row reads with its type's settings.** For a `cases` collection, the server stamps the serving portal's settings for the row's case type on the row as `_columns`; the site's table and record page take `render` and `linkLabel` from there, so one list can show a QR code on one case type and a plain link on another.
- **The admin section.** On a portal's Case types page, each case type opens "Kolommen in Mijn zaken": per field a read-only Veld, a Weergave choice, Tekst van de link for a link or QR code, and "Zo ziet de inwoner het" with nextcloud-vue's `CnQrCode` (^2.175.0) for a QR code.

## Capabilities

### Modified capabilities

- `portal-case-type-visibility`: ADDED requirements for the per-case-type column settings and how a case row reads them.

### Capability row

`dem-tnd-show-per-casetype` (built) names PtZaaktypeWeergave; this change builds that board's columns section and does not reopen the row.

## Impact

- **Backend**: `lib/Service/CaseTypeColumns/` (normaliser, settings, the row stamp, the declared `cases` collections), `CaseTypeVisibility::servingColumns`, `PortalCaseTypesController::index` and `::updateColumns`, `ContributionController::screenRows` and `::screenRow`, `appinfo/routes.php`, the register.
- **Admin**: `src/widgets/CaseTypeColumns.vue`, `src/lib/caseTypeColumns.js`, `src/widgets/PortalCaseTypes.vue`.
- **Site**: `rowColumn()` in `src/site/components/collections/cells.js`, used by `CollectionTable.vue` and `detailFields()`.

## Not in this change

- Adding a column the case app does not show. The settings change how a declared field reads, not which fields a case exposes.
- The board's other sections on the same page (statuses, document types, results, notifications, the case number column). They belong to the case app's case type screens.
