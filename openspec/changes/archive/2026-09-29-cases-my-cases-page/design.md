# Design: cases-my-cases-page

Read at portaliq `development` `eeda3fa`.

## Where it sits today

- `lib/Controller/MyCasesController.php:83` `index()`: bearer to subject;
  `PortalCaseListReader::listCases()` over every collection with `kind`
  `cases` (`lib/Service/PortalCaseListReader.php:50,84`), each row tagged with
  `_source` (`appId`, `label`, `register`, `schema`, `collection`), sorted by
  `created` or `startedAt`, newest first; then the mandates held
  (`PortalMandateService::mandatesFor()`), the active one chosen by the
  `mandate` request parameter (:99-100), its reach resolved by
  `PortalPartyTreeResolver` with a `409 group_too_large` refusal (:109-118), and
  the mandated cases merged in. It answers `{ cases, mandates, activeMandate }`.
- `lib/Contribution/CollectionConfigNormaliser.php` normalises a collection's
  config fail closed; it knows no closed marker.
- `src/portal/App.jsx` `buildNav()` (:48-76) with the fixed entries
  `special: 'tasks'` and `special: 'inbox'`; the header (:319-323) has the
  sign-out button; `src/portal/lib/portalApi.js` has no my-cases call.
- `src/portal/components/PageView.jsx:339-347` renders a `citizenCase` block
  (`CitizenCase.jsx`) for a selected row of a collection.
- `CitizenCaseController` reads the `mandate` parameter too
  (`lib/Controller/CitizenCaseController.php:682`).

## D1. One fixed page, same shape as Inbox and Tasks

`buildNav()` gains `{ key: CASES_KEY, label: t('My cases'), special: 'cases' }`,
placed first, shown when the contributions answer announces at least one
`kind: cases` collection (a flag the contributions endpoint adds, like
`tasks.enabled`). `src/portal/components/MyCasesPage.jsx` calls a new
`api.fetchMyCases(mandateId)`.

## D2. The closed marker is declared, never guessed

A collection may declare `closedField`, a field name among its projected
`fields`. A row is closed when that field is present and not empty.
`CollectionConfigNormaliser` keeps `closedField` only when it names a projected
field; anything else is dropped. `PortalCaseListReader` adds
`_closed: true|false` to each row. A collection without `closedField` yields
`_closed: false` for every row, so nothing is hidden by a guess.

The page shows two tabs, "Open" and "Closed", with their counts. The "Closed"
tab is hidden when no collection declares `closedField`.

## D3. The switcher sits in the header and scopes every case read

When `mandates` is not empty, the header shows "Acting for" with yourself plus
one entry per mandate, by its `label`. The choice is stored in session storage
and sent as `mandate` on `fetchMyCases()` and on every case screen read
(`fetchCitizenCase()`), so the case screen and the list agree. A `409
group_too_large` shows "This organisation has too many cases to list here.
Choose a narrower mandate."

**Fixed in the second PR of this change, read at `21d2951`.** The case screen did not read a
case under a mandate at all: `CitizenCaseController::context()` reads by the
person's own scope field, and the `mandate` parameter only stamped the entity
onto a write (`actingAs()`). Sending `mandate` on `fetchCitizenCase()` alone
would have opened nothing. So `show()` now falls back, only on
`case-not-yours`, to `MandatedCaseReader`, which asks
`PortalCaseListReader::listMandatedCases()` for the one named mandate and
picks the case out of that list. The screen can therefore never open a case
the list would not show. It is read-only: the window and documents are
closed with "You are viewing this case on behalf of {label}. It cannot be
changed here.", no documents are listed, and every write still goes through
the person's own ownership check. `mandate=self` means "yourself": without it
`PortalMandateService::activeMandate()` spends the first mandate held, which
would make "yourself" impossible to choose.

The mandates held are learned from the `GET /portal/api/my-cases` answer
("My cases" is the page the portal opens on), and a refusal never forgets
them. A row opened from "My cases" hands its row to the case app's page, so a
mandated case, which is not in the person's own rows, still opens there; the
case screen reads it under `row._mandate.id`.

## D4. A row opens where the case lives

A row carries `_source.collection`. Choosing it switches to the nav page of
that contribution which holds a `citizenCase` or `detail` block for the
collection, with the row selected, the same way the inbox hands a task uuid to
"My tasks" (`pendingTaskUuid`, `src/portal/App.jsx:177`). When no page of the
contribution shows the collection, the row is not a link.

## Risks

- Every contributing app must add `kind: 'cases'`. Until dossiq does, the page
  is empty for dossiq cases; the empty state says "No cases yet." and the
  sibling half is named in the proposal.
- A mandate chosen in one tab applies to every tab of the session. That is
  deliberate: acting for someone is a session state, not a per-page filter.

## What it deliberately does not do

- It does not change `MyCasesController`'s scoping or merge.
- It does not record mandates.
