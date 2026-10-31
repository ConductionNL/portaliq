## Why

A create action writes its answers onto the object as they are named. dossiq's Woo request asks "Waar gaat uw verzoek over?" as `onderwerp`, but the case keeps that as `title`, and the request as sent under `wooRequest`. Because of that, dossiq kept the Woo form as an endpoint action, and an endpoint action can keep no draft (REQ-RR3-005, decision 180). Ruben's decision a1 makes the Woo request a plain create action, with dossiq's per-case-type intake doing the rest. That only works if the create action can say where each answer lands.

## What Changes

- A create action MAY declare `fieldMap`, which maps an answer name to a dotted field path on the object it creates, for example `{"onderwerp": "title", "periodeVan": "wooRequest.periodeVan"}`.
- The answers are checked under their own names first: the whitelist, `requiredFields` and cross-references. Only then is each mapped answer moved to its path.
- A draft is written mapped the same way. Reading a draft back reverses the map, so the form gets its answers under their own names.
- Without `fieldMap`, nothing changes.
