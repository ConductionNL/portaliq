# Proposal: mijn-taken-lists-contributed-tasks

## Why

Seen in the Zuiddrecht go-live rehearsal R2: Sanne de Vries' overview says under "Wat u nog moet doen" that she has to send the drawing of her dormer ("Stuur de bouwtekening van uw dakkapel", case 2026-0009), but "Mijn taken" said "U heeft geen open taken".

The two read different things. The overview's block is dossiq's `tasks` block over `vragenAanU`, its requests for more information. "Mijn taken" read only the portal tasks of OpenRegister's task seam (`/portal/api/tasks`), and dossiq asks for a missing document through its own collection, not through that seam. The task proxy and the loopback answered correctly: 200, no portal tasks for Sanne.

## What changes

- "Mijn taken" also lists the rows of every collection a contribution shows in a `tasks` block, once per app and collection, titled and dated as that block says, after the portal tasks. Each opens the page that shows it, with its row picked; a row no page lists opens the record its block's `recordField` names, so dossiq's request opens its case.
- A collection's `defaultFilters` decide which of its rows are listed, so an answered request is not a task.
- "U heeft geen open taken" shows only when neither source has anything. A failed portal task read still says so above the app tasks.

## Not changed

- The portal task proxy, its assertion and its completion.
- What a contribution's own `tasks` block shows.
