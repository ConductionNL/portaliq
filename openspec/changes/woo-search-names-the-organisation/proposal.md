# Proposal: woo-search-names-the-organisation

## Why

Seen in the Zuiddrecht go-live rehearsal R2: the Woo search's "Organisatie" filter read `bacda7a4-b1ae-4a51-9c3e-c1b00c0bc596 (13)`. A publication's `organization` is the id of the OpenRegister organisation that published it, and opencatalogi's facet answers that id as its label. A visitor cannot read an id.

## What changes

- The public site record (`/api/content/site`) carries `organisation: {id, name}`: the id and the name of the organisation the portal belongs to, resolved from its `organisation` slug. Null when the slug names no organisation.
- The host hands the search block a name per facet value it knows (`facetNames`), after the authored props, so the organisation filter reads "Gemeente Zuiddrecht".
- `labelBuckets()` takes those names; a configured value list still names its codes, and any other bucket keeps the label the API gave it.

## Not changed

- Which publications the filter selects: the value sent is still the id.
- An organisation of another catalogue the portal federates with keeps the label its API gives it.
