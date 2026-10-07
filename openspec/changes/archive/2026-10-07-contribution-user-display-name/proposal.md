# Proposal: contribution-user-display-name

## Why

Ruben reviewed the primary-school parent portal recordings (2026-10-02). Tables on the parents' pages showed a teacher's Nextcloud user id, such as "po-leerkracht-09". A parent wants to read who the teacher is, and a user id is an internal key that should not reach a resident at all.

## What changes

- A collection column may declare `"render": "user"`. Its value is a Nextcloud user id (or a list of them).

  ```json
  { "field": "handledBy", "label": "Leerkracht", "render": "user" }
  ```

- Portaliq replaces that value with the user's display name on the server, in the collection list (`GET /portal/api/collections/{register}/{schema}`) and on the single object (`GET /portal/api/collections/{register}/{schema}/{id}`), before the row leaves the server. A list of user ids becomes a list of names.
- Fail-closed: a value that names no user on the instance, or is not a user id, is answered as `''`, never as itself. The user id never reaches the browser.
- `CollectionConfigNormaliser` keeps `user` as a render kind (before this change it fell back to `text`).
- The site renders the value as ordinary text; no front-end change is needed.

## For the contributing apps

learniq marks its teacher columns with `"render": "user"`. The field must still be in the collection's `fields` whitelist (when one is declared) for the value to be read at all.

## Not changed

- Form fields, detail-only fields (`detail.fields` without a column), the inbox and timeline read their values as before.
