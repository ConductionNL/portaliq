---
kind: code
depends_on: [woo-journey-entry-points, portal-contribution-contract]
---

# Proposal: my-dossiers

## Why

"Mijn dossiers" and "Mijn zoekopdrachten" are pages opencatalogi contributes to
the signed-in portal (hydra `woo-citizen-journey`, contract C7). The question
for portaliq is what the contribution runtime already renders without any
portaliq code, and what it cannot.

Read at `development` `0e0cfc8d`, it already renders:

- a list of the resident's own dossiers and saved searches (a `collection`
  block, scoped on `owner`), with declared columns;
- a new dossier and a changed saved search (a `create` or `update` action with
  whitelisted fields, for example `title`, `frequency` and `active`);
- one dossier's fields (a `detail` block), and endpoint row actions with a
  confirm step, for example share, revoke and delete.

It cannot render three things the journey needs:

- **The dossier's items.** `DetailCard` prints every field with `String()`, so
  `items[]` reads "[object Object],[object Object]". The items also hold only
  a publication id: the title, the link and whether the publication is still
  public (J3.5) live in opencatalogi and must be read when the dossier opens.
- **Removing one item.** A row action acts on the whole dossier and sends no
  item id.
- **The share link.** A share action answers with the link, and the confirm
  step says "Done." and drops it.

## What changes

- **An item list on a collection's detail.** A collection may declare
  `itemList: { label?, provider, removeAction? }`. `provider` names a public
  method on the app's provider that takes the object id and returns the items.
  The portal calls it only after the resident's own scoped read of that object
  succeeded, the same rule as `timeline`. Each item shows its title, as a link
  when it has one, its note, and "Niet meer openbaar" when `public` is false.
- **Remove one item.** `removeAction` names an endpoint row action of the same
  contribution with the field `itemId`. Each item gets a remove button that
  forwards `{ itemId }` with the proven dossier id.
- **A link in an action's answer is shown.** When an endpoint row action answers
  with `link`, the confirm step shows it in a read-only field with a copy
  button, instead of only "Done.".

## Hydra requirements it implements

- "A resident's dossier MUST be owned by the resident and readable by nobody else unless shared" (the portal half)
- "A shared dossier MUST show only what is public at the moment it is read" (the owner's view: "Niet meer openbaar")

## Out of scope

- A note on one item. opencatalogi can offer it as an endpoint row action with
  the fields `itemId` and `note` later; this change renders the note only.
- The collection and saved-search schemas and the contribution itself:
  opencatalogi (C1, C2, C7).
- The public view of a shared dossier: opencatalogi serves it at its own route.
