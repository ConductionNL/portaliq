# 0006. Integration leaves are for staff only and never reach a portal response

- Status: accepted
- Source: [`README.md`](../../README.md), section "Integration leaves, and the side of the boundary each one lives on"

## Context

Portaliq adopts integration leaves from other Nextcloud apps (`forms`, `talk`,
`calendar`) on its internal staff pages. A leaf is a Nextcloud component for a
Nextcloud user. A portal visitor is neither: they hold a portal bearer session
and reach data only through the portal edge.

## Decision

Every leaf is internal only. No visitor sees a leaf, and no Talk join link, form
share link or calendar link reaches a portal response. Anything a visitor needs
is built from OpenRegister objects and served by the portal edge.
`CmsReader::shapePage()` copies a whitelist of widget keys, so a leaf-shaped
widget authored into a portal page is stripped.

## Consequences

- `tests/Unit/Service/CmsReaderTest.php` pins the stripping.
- Each leaf is declared both as a widget in `src/manifest.json` and as
  `configuration.linkedTypes` on its schema in `lib/Settings/portaliq_register.json`;
  `npm run check:leaf-integrations` checks the two agree.
