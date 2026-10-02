## ADDED Requirements

### Requirement: A contributed page MAY place itself in the menu, per record, or as home (REQ-SMO-020)

The page resolver MUST keep these page keys and drop malformed ones: `group` (a string of 1 to 80 characters), `menu` (only the value `false`), `perRecord` (a collection id of the contribution), `records` (`{ collection, titleFields?, subtitleFields? }`, or a bare collection id read as `{ collection }`) and `home` (only `true`). `perRecord` MUST be dropped unless the page is a record page on the same collection. A page with `menu: false` MUST keep its route and MUST NOT appear in the menu. A page with `perRecord` MUST appear in the menu once per row of that collection the resident may read, under a group titled by the row.

#### Scenario: Old routes stay, the menu shrinks
- GIVEN learniq's fifteen guardian collection pages declare `menu: false`
- WHEN the guardian opens `/mijn/learniq/parentGrades` from a bookmark
- THEN the page renders
- AND the menu does not list it

#### Scenario: A page per child
- GIVEN a page "Afwezigheid" with `record: { collection: parentChildren }` and `perRecord: parentChildren`
- AND the guardian may read Vera and Sami
- WHEN the menu renders
- THEN "Afwezigheid" appears under "Vera" and under "Sami", each linking to that child's record

#### Scenario: A perRecord on another collection is dropped
- GIVEN a page with `record: { collection: parentChildren }` and `perRecord: parentGrades`
- WHEN the contribution is normalised
- THEN the page has no `perRecord`

### Requirement: A contributed page MAY use the tasks, inbox, cases, steps, documents and timeline blocks (REQ-SMO-021)

The block resolver MUST accept the block types `tasks`, `inbox`, `cases`, `steps`, `documents` and `timeline`. `tasks` and `cases` MUST name a collection of the contribution; `inbox` MAY name a `kind: inbox` collection. `steps`, `documents` and `timeline` MUST be dropped unless the page is a record page whose collection declares that provider. A `collection` block MAY declare `limit` (an integer 1 to 50) and `sort` (`{ field, direction }` with `asc` or `desc`, on a projected field). A `calendar` block MAY declare `range` (`week` or `month`). An out-of-range or unknown value MUST be dropped, leaving the block as it was without it.

#### Scenario: The three newest grades
- GIVEN a `collection` block on `parentGrades` with `limit: 3` and `sort: { field: gradedAt, direction: desc }`
- AND the guardian's child has eight grades
- WHEN the block renders
- THEN it shows the three newest and a link to all grades

#### Scenario: This week only
- GIVEN a `calendar` block with `range: week`
- WHEN the guardian opens the overview on Friday 2 October 2026
- THEN the block lists only items from Monday 28 September to Sunday 4 October

#### Scenario: A placeholder name is not a block
- GIVEN a page declares a block of type `caseCards`
- WHEN the contribution is normalised
- THEN the block is dropped

### Requirement: A cases collection MAY supply steps, an answer date and whose turn it is (REQ-SMO-022)

A `cases` collection MAY declare `steps: { label?, provider }`, naming a provider method that answers a list of `{ label, description?, state, date? }` for one case, with `state` one of `done`, `current`, `todo`. It MAY declare `dueField` and `turnField`, each kept only when it names a projected field. Portaliq MUST drop a steps entry that does not fit the shape and MUST call the provider only for cases on screen.

#### Scenario: Dossiq's folded steps
- GIVEN `mijnZaken` declares `steps: { label: "Waar staat uw aanvraag?", provider: caseSteps }`
- WHEN the case page renders the steps block for case 2026-0003
- THEN it shows the provider's steps under "Waar staat uw aanvraag?"

#### Scenario: A turn field that is not projected
- GIVEN `turnField: waitingOn` and `waitingOn` is not in the collection's `fields`
- WHEN the manifest is normalised
- THEN the collection has no `turnField`

### Requirement: A via join MAY grant only through live join rows (REQ-SMO-023)

A `via` declaration MAY carry `when: { field, in: [scalars] }` and `validUntilField`. A join row MUST grant access only when its `when` field holds one of the listed values, and only when its `validUntilField` date is empty or not in the past. A malformed `when` or `validUntilField` MUST fail the whole join closed, to zero rows. The check MUST run where every reader's join is verified, so cases, inbox, collections, timelines, row actions and change notices all honour it.

#### Scenario: A withdrawn enrolment shows no timetable
- GIVEN a pupil's collection joins `enrolment` with `when: { field: status, in: [active] }`
- AND the pupil's only enrolment in group 3B has status `withdrawn`
- WHEN the pupil opens the timetable
- THEN no session of group 3B is listed

#### Scenario: An expired share grants nothing
- GIVEN an assessor's collection joins `portfolio-share` with `validUntilField: expiresAt`
- AND the share expired on 1 October 2026
- WHEN the assessor opens the entries on 2 October 2026
- THEN no entry of that share is listed

#### Scenario: A malformed filter fails closed
- GIVEN a `via` with `when: { field: status }` and no `in`
- WHEN the collection is read
- THEN it returns zero rows
