# Proposal: the-owning-apps-refusal-reads-as-no-longer-possible

## Why

The app that owns a record can refuse a write on it before it is stored: decidiq refuses a resident's reaction on a consultation that is closed (its open-parent guard, a `HookStoppedException` from OpenRegister's creating hook). decidiq's live run of `portal-citizen-contribution.spec.ts` (25 Nov 2026) found that portaliq answers that refusal with 502 `write_failed`, the answer for a store that is down. The site then says "Opslaan is niet gelukt." where the scenario asks it to say that reacting is closed.

OpenRegister's own API answers the same refusal as a client error (422 with the hook's message). The site already reads a 409 as "Dit kan voor dit item niet meer." (site-forms, "A refused answer must say in plain words which field to change").

## What changes

- `PortalObjectWriter` records whether its last create or update failed because the owning app refused it (OpenRegister's `HookStoppedException`), next to `lastFailure()`.
- `ContributionController::create()` answers such a refusal with 409 `{error: "refused"}`. The owning app's own text stays on the server, as for every store refusal. A value the store refused still answers 422 `invalid`; anything else still answers 502 `write_failed`.

## Not in this change

- Updates keep their current answers (a refused update is still read as 404 or 422): the scenario that found this is a create.
- No new site text: the site's 409 text already fits.
