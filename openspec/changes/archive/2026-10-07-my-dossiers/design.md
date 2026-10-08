# Design: my-dossiers

Read at portaliq `development` `0e0cfc8d`.

## What exists

- `PortalTimelineController::show()` reads the object through the resident's
  scope (`PortalObjectReader::readObject`), then calls the provider method named
  in the collection's `timeline` through `PortalTimelineReader`. Route
  `/portal/api/collections/{register}/{schema}/{id}/timeline`.
- `TimelineProviderMethod` decides whether a method name may be called on a
  provider (a plain identifier, not a contract method).
- `PageView.jsx` `DetailCard` renders fields, files, proposals and the timeline.
- `RowActionConfirm.jsx` runs `runRowAction()` and shows `outcomeKey()`.

## D1. The `itemList` declaration

`ItemListConfigNormaliser` keeps `itemList` only when `provider` passes
`TimelineProviderMethod`, `label` is a string when present, and `removeAction`
names an endpoint row action of the same contribution whose `fields` include
`itemId`. A bad `removeAction` drops only that key. A bad `provider` drops the
whole `itemList`. It never adds or widens an action.

## D2. Reading the items

`GET /portal/api/collections/{register}/{schema}/{id}/items`,
`PortalTimelineController::items()` (route `portalTimeline#items`), shares the
timeline's ownership proof in one private method: bearer,
the collection in the resident's own manifest, trust, the scoped read of the
object (404 when it is not theirs), then the provider method. `PortalItemReader`
calls the method and keeps per item only `id`, `title`, `url`, `note`,
`public` (bool, default true) and `addedAt`. A `url` that is not https or an
instance-local path starting with a single `/` is dropped. A method that throws
or returns a non-array answers 502; the rest of the dossier still renders.

## D3. Rendering

`ItemList.jsx` under the detail card: a heading (`label`, default "In this
dossier" / "In dit dossier"), one row per item with the title (a link when
`url`), the note, and "No longer public" / "Niet meer openbaar" when `public` is
false. Empty: "Nothing in this dossier yet." / "Er staat nog niets in dit
dossier.". With `removeAction`, each row has "Remove" / "Verwijderen", which
forwards `{ itemId }` through `api.forwardRowAction()` and reloads the list.

## D4. The link in an answer

`rowAction.js` `answerLink(result)` returns `result.body.link` when it is https
or instance-local. `RowActionConfirm` shows it in a read-only input with a
"Copy link" / "Kopieer link" button after a successful run.
