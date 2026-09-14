# Tasks: portal-visibility-follows-the-party-tree

## The mandate

- [ ] **T01**: Add the reach on a mandate: the organisation named, or that organisation and the entities below it; default the organisation named (REQ-PTV-001)
- [ ] **T02**: Read the case type's declaration on whether its cases may be reached through a parent, and fail closed when it says nothing (REQ-PTV-002)

## The walk

- [ ] **T03**: Resolve the scope by walking openregister's party relations at request time, with no hierarchy stored in portaliq (REQ-PTV-003)
- [ ] **T04**: Bound the walk by depth and page size, and refuse with an explanation past the bound (REQ-PTV-004)

## What the user sees

- [ ] **T05**: Name the entity and the mandate on every case reached through the tree (REQ-PTV-005)
- [ ] **T06**: Offer an entity below the mandated one in the organisation switcher, and record the mandate on every write (REQ-PTV-006)

## Quality

- [ ] **T07**: PHPUnit: a mandate without reach sees only its own organisation, a refusing case type is excluded, a sold subsidiary disappears, the bound refuses rather than truncates
- [ ] **T08**: Playwright `tests/e2e/portal-visibility-follows-the-party-tree.spec.ts`: a parent with one mandate sees two subsidiaries' cases, each naming its entity
- [ ] **T09**: Dutch and English strings; docs; `openspec validate portal-visibility-follows-the-party-tree --type change --strict`
