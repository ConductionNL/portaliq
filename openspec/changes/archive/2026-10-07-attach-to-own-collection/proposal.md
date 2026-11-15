---
kind: code
depends_on: [woo-journey-entry-points]
---

# Proposal: attach-to-own-collection

## Why

A resident who reads the answer to their question about a Woo dossier cannot
reply on the portal (hydra woo-citizen-journey J4.3). pipelinq declares
`replyToQuestion`, and no page shows it.

The action needs a form (the reply text) on one record, the resident's
question. The contract has exactly one surface for that: an attached action on
the detail card (REQ-WJE-004). Three gaps keep pipelinq from using it:

- `attachTo` reaches every collection on a schema. pipelinq has up to six
  `ticket` collections, and the reply belongs on the questions only.
- The forward refuses an action attached to its own app's collection. It takes
  the row-action path instead, which needs `rowActions`, and a row action adds a
  table button that forwards without asking the reply text.
- The listing drops `rowWhen`, so a renderer offers the reply on a question
  that is not waiting for the resident. The server then refuses it with 409.

## What changes

- `attachTo.collection` (optional) narrows an attachment to that one
  collection of the target app. A malformed value attaches nothing.
- The target may be the action's own app. `?actionApp=` on the forward always
  takes the attached path, which still requires the attachment.
- The listing carries `rowWhen`. `attachedActionsOf(collection, row)` leaves
  out an action whose `rowWhen` the row does not meet, in the React portal
  (`AttachedActions.jsx`). The forward keeps refusing such a row with 409.

## Out of scope

- The Vue site's `AttachedActions.vue` came with the slice-c PR
  (portaliq#1029) and passes the row to `attachedActionsOf(collection, row)`
  since T04.
