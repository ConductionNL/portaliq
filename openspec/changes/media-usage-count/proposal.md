# Proposal: media-usage-count

## Why

The media board ([portaliq/PtMedia](https://identity.conduction.nl/screens/board?id=portaliq/PtMedia)) shows per item "Gebruikt op 3 pagina's" and has the pill "Nergens gebruikt 7", so an editor can clean up what no page uses. A media item does not know where it is used: pages, news and the portal refer to it as `media:<id>` (or the logo address), and nothing counts those references.

## What Changes

- Media schema 0.2.0: `usedOn`, an integer, how many records of the item's portal refer to it. Register 0.99.0. Additive.
- `MediaUsage` counts references per item in the portal record, its pages and its news (hero image, share image, markdown links, photos, the logo address; a record counts once per item) and writes only the counts that changed.
- `MediaUsageJob`, hourly.
- The Media list gets the column "Gebruikt op" and the pill "Nergens gebruikt" (`usedOn: 0`).

## Impact

- `lib/Service/Cms/MediaUsage.php`, `lib/BackgroundJob/MediaUsageJob.php`, `appinfo/info.xml`, `lib/Settings/portaliq_register.json`
- `src/manifest.json` (page Media), l10n
- Shared blocks are organisation-wide, not per portal, and are not counted yet. An item reads no count until the first run after the upgrade.
