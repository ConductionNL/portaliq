# Design: a-resident-opens-one-message

## Screens

The page follows the Zuiddrecht board **BerichtDetail** ("Mijn Zuiddrecht: bericht"). It sits in the Mijn layout, with the resident menu on the left and "Berichten" marked current. The breadcrumb reads "Home / Mijn Zuiddrecht / Berichten / Bericht".

| Board element | Source |
|---|---|
| "Terug naar berichten", top and bottom | A link to the inbox page. It keeps the tab the resident came from when the list had tabs. |
| Title | The message's title field (REQ-IMF-001). |
| "Ontvangen op {date} om {time}" | `receivedAt`, in the portal's language, without seconds. |
| "Van {sender}, over zaak {number}" | The sender field and the record link's label. The case number links to the record page. |
| "Gelezen" badge | Shown once the message is read. A text badge, not a colour alone. |
| Case card with "Naar uw zaak" | The record link (`recordLink`): its title, its number and the record page's route (`recordRoute`). No card without a record link. |
| "Over dit bericht" | A description list: Van, Over zaak, Ontvangen, and Aard, Rechtsgevolg, Termijn when the message carries them (the 2:10 metadata of the supplier-portal spec). A row without a value is left out. |
| "Bericht" | The message text, with the same link handling as the list (`bodyParts`). |
| "Bijlagen" | The files of `_files`, each as a link with name, type and size ("PDF · 412 kB"). Shown only when the collection declares `filesDownload`. |
| "Naar uw zaak" at the bottom | The same record route as the card. |

Two pieces are not on the board, because the board's Woo decision declares neither:

- The reply form. It sits below "Bijlagen", the same `InboxReply` component the list uses.
- The action button (`actionField`). It sits next to "Naar uw zaak", as the primary button, and "Naar uw zaak" becomes secondary.

## Route

`/mijn/<inbox page slug>/<source id>/<message id>`. The source id is the inbox collection's id (`_source.id` on every inbox row). The site resolves it to `register` and `schema` from the resident's own contributions payload. An unknown source shows the not-found state without calling the server.

The list links each message's title to this route. A click opens the page in the site router; a new tab opens the same page from a cold load.

## Endpoint

`GET /portal/api/inbox/{register}/{schema}/{id}?collection=<source id>`, `#[PublicPage]`, `#[NoCSRFRequired]`, rate limited like the inbox (60 per minute).

In order, before any read:

1. No portal session: 401.
2. `authorisedInboxCollection()` finds a `kind: inbox` collection with that register and schema in the subject's own contributions. None: 404.
3. The collection's `minTrust` is re-checked against the session. Too low: 403.
4. The scope value is resolved from the collection's `scopeClaim`, as mark-read does. None: 404.
5. The object is read with the scope field, the scope value and the organisation as filters, through the same scoped reader as `object()`. No row: 404. A row of another resident or another organisation is never fetched, so it answers the same 404 as an id that does not exist.

The row then goes through the same projection as one inbox row in `PortalInboxReader`: message fields, `_source`, `_files` when opted in, the reply declaration and `deletable`. To keep one projection, `collectionRows()` and the new read share a private `projectRow()`.

The endpoint writes nothing. Opening an unread message calls the existing `PATCH .../read`, so the read receipt rules (REQ-IRR-002) stay in one place.

## Acting for someone else

The endpoint uses the same subject as `GET /portal/api/inbox`. Under a mandate the inbox shows what the inbox shows today; this change adds no reach.

## Errors

- 404: the page reads "Dit bericht bestaat niet of is niet voor u." with the back link. It never says which of the two.
- 401: the site's normal sign-in redirect, back to this route after sign-in.
- Any other failure: the existing `LoadError` block with "Opnieuw proberen".

## Accessibility

- The title is the page's `h1`. "Over dit bericht", "Bericht" and "Bijlagen" are `h2`.
- "Over dit bericht" is a `dl`.
- Each attachment link names the file, type and size in its text.
- Focus moves to the `h1` after the page loads in the site router.
