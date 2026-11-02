# Proposal: the Zuiddrecht Woo cases are on the five-step version

## Why

dossiq ships version 2 of the Woo request case type (dossiq `r24/woo-five-steps-v2`) with the five steps every case board draws: Ontvangen, In behandeling, Besluit, Publiceren, Afgehandeld. Both versions share the identifier `woo-verzoek`, so a lookup on the identifier alone matches two rows, and the installer refuses it. The status names of version 1 (Ontvangst, Beoordeling ontvankelijkheid, …) no longer exist on version 2.

## What changes

- Every Woo case type lookup in the fragment names `version: 2`.
- Every status lookup on it names the version 2 step: Ontvangst becomes Ontvangen; Beoordeling ontvankelijkheid, Zoeken documenten, Beoordelen documenten and Lakken / Anonimiseren become In behandeling; Publicatie becomes Publiceren.
- The Lindelaan case reads Ontvangen then In behandeling, as DqZaak draws; its sub-cases In behandeling and Ontvangen.

## Depends on

dossiq `r24/woo-five-steps-v2`. Released together: on a dossiq without version 2 the lookups find no row.
