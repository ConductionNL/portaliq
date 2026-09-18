# Tasks: portal-visibility-follows-the-party-tree

## The mandate

- [x] **T01**: Add the reach on a mandate: the organisation named, or that organisation and the entities below it; default the organisation named (REQ-PTV-001)
- [x] **T02**: Read the case type's declaration on whether its cases may be reached through a parent, and fail closed when it says nothing (REQ-PTV-002)

## The walk

- [x] **T03**: Resolve the scope by walking openregister's party relations at request time, with no hierarchy stored in portaliq (REQ-PTV-003)
- [x] **T04**: Bound the walk by depth and page size, and refuse with an explanation past the bound (REQ-PTV-004)

## What the user sees

- [x] **T05**: Name the entity and the mandate on every case reached through the tree (REQ-PTV-005)
- [x] **T06**: Offer an entity below the mandated one in the organisation switcher, and record the mandate on every write (REQ-PTV-006)

## Quality

- [x] **T07**: PHPUnit: a mandate without reach sees only its own organisation, a refusing case type is excluded, a sold subsidiary disappears, the bound refuses rather than truncates
- [x] **T08**: Playwright `tests/e2e/portal-visibility-follows-the-party-tree.spec.ts`: a parent with one mandate sees two subsidiaries' cases, each naming its entity
- [x] **T09**: Dutch and English strings; docs; `openspec validate portal-visibility-follows-the-party-tree --type change --strict`

## Where it lives

The reach is on the mandate (`portalMandate.reach`, default `organisation`).
`lib/Service/Identity/PortalPartyTreeResolver.php` walks openregister's party
relations at request time, bounded by depth and page size, and refuses rather
than truncates. `PortalCaseListReader::listMandatedCases()` reads one entity at
a time and excludes any case whose type the contribution has not declared
parent-reachable. `MyCasesController` turns a refused walk into a 409 naming the
bound, and offers the reached entities to the switcher.
`CitizenCaseController::actingAs()` resolves the entity a write is made for,
and `CitizenWriteRecorder::mandate()` records it. The switcher itself is SPA
work over `activeMandate.entities`.

