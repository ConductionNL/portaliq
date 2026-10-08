---
kind: code
depends_on: []
---

# Proposal: inbox-shows-portal-messages

## Why

A resident's portal inbox never showed the notices portaliq writes itself: an
answered question (`pipelinq.question.answered`), a matched saved search, a
published Woo decision, a submission receipt. On a live instance
`GET /portal/api/inbox` answered `{"messages":[]}` while a `portalMessage` for
that resident existed, so only the e-mail reached them.

`PortalInboxReader::aggregateInbox` merges only collections an app declares
`kind: inbox`. No contribution declares one over `portaliq/portalMessage` for
residents; portaliq's own provider is config-driven per audience and only the
dev supplier page declares it. The Woo e2e asserted that the object exists, not
what the inbox serves, so it stayed green.

hydra `woo-citizen-journey` requires every answer, decision and alert to reach
the resident through portaliq's notice path, delivered to the portal inbox.

## What changes

- `portalMessage` is a built-in inbox source of every resident. It is read
  through the same scoped reader as a declared source: on `subjectRef`, with the
  bearer's own reference and organisation, fail-closed without a subjectRef.
- A notice a declared inbox collection already returns is shown once, under the
  declared collection. A row without an id is left out.
- Mark-read accepts the built-in source (`?collection=portalMessages`), still
  writing only `read` and still scoped on the bearer's own subjectRef.
- The Woo e2e asserts what `GET /portal/api/inbox` serves in J4, J5 and J6, and
  marks the J4 notice read.

## Design choice

The reader reads `portalMessage` directly instead of portaliq's provider
declaring a `kind: inbox` collection. The provider contributes only for
audiences that have an active `portalPage`; a resident audience without one
would still get nothing. A collection added to the aggregate also reaches the
navigation, the case list and every other manifest consumer, which this fix has
no reason to touch.
