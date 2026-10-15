## ADDED Requirements

### Requirement: My tasks lists what the apps ask of the resident

"My tasks" MUST list, beside the resident's open portal tasks, the rows of every collection a contribution shows in a `tasks` block, once per app and collection, titled and dated as the first such block says. Only rows that match the collection's `defaultFilters` are listed. Each such row MUST open the page that shows its collection, with the row picked. The page MUST say there are no open tasks only when neither the portal tasks nor any such collection has a row.

#### Scenario: A request for a missing document
- GIVEN dossiq shows `vragenAanU` in a `tasks` block on its overview
- AND Sanne has an open request "Stuur de bouwtekening van uw dakkapel"
- WHEN she opens "Mijn taken"
- THEN the request is listed with its deadline
- AND choosing it opens dossiq's page with the request picked
- @e2e exclude pinned by `tests/tasks-page.spec.mjs` "my tasks lists what an app asks beside the portal tasks, and is empty only when both are"; the live check on the rehearsal follows the merge

#### Scenario: An answered request is no task
- GIVEN `vragenAanU` declares `defaultFilters: {state: "open"}`
- AND a request has `state: "answered"`
- WHEN "Mijn taken" lists the tasks
- THEN that request is not listed
- @e2e exclude pinned by `tests/tasks-page.spec.mjs` "my tasks reads every collection an app shows in a tasks block, once"
