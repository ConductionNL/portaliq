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

The block resolver MUST accept the block types `tasks`, `inbox`, `cases`, `steps`, `documents` and `timeline`. `tasks` and `cases` MUST name a collection of the contribution; `inbox` MAY name a `kind: inbox` collection. `steps`, `documents` and `timeline` MUST be dropped unless the page is a record page whose collection declares that provider. A `collection` block MAY declare `limit` (an integer 1 to 50) and `sort` (`{ field, direction }` with `asc` or `desc`, on a projected field). A `calendar` block MAY declare `range` (`day`, `week` or `month`). An out-of-range or unknown value MUST be dropped, leaving the block as it was without it.

#### Scenario: The three newest grades
- GIVEN a `collection` block on `parentGrades` with `limit: 3` and `sort: { field: gradedAt, direction: desc }`
- AND the guardian's child has eight grades
- WHEN the block renders
- THEN it shows the three newest and a link to all grades

#### Scenario: This week only
- GIVEN a `calendar` block with `range: week`
- WHEN the guardian opens the overview on Friday 2 October 2026
- THEN the block lists only items from Monday 28 September to Sunday 4 October

#### Scenario: Today only
- GIVEN a pupil's `calendar` block with `range: day`
- WHEN the pupil opens the overview on Friday 2 October 2026
- THEN the block lists only that day's lessons

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

### Requirement: A cta block MAY open a page or a site route, for the open record, with the record in its label (REQ-SMO-024)

A `cta` block MUST name exactly one of: an `action` of the contribution (as today), a `page` id of the contribution, or a `route` inside the portal. A `route` MUST start with a single `/`, MUST NOT carry a scheme, a host or `//`, and is dropped otherwise. On a record page a cta MAY declare `withRecord: true`: a page or route then opens with the open record chosen, and an action opens with the field named by its `recordField` preset to the record. The `label` MAY hold `{title}`, filled with the open record's title as plain text. A cta that names none or more than one target MUST be dropped.

#### Scenario: Report Vera sick from the overview
- GIVEN the guardian overview with `records: parentChildren` and Vera chosen
- AND a cta with `action: createExcuseRequest`, `withRecord: true` and label "{title} ziek of afwezig melden"
- WHEN the guardian presses "Vera ziek of afwezig melden"
- THEN the absence form opens with Vera chosen

#### Scenario: A tile to a page
- GIVEN a cta with `page: parentGrades` and `withRecord: true` on Sami's overview
- WHEN the guardian presses it
- THEN the grades page opens with Sami chosen

#### Scenario: An outside address is refused
- GIVEN a cta with `route: "//example.org/x"`
- WHEN the contribution is normalised
- THEN the block is dropped

### Requirement: Tasks and inbox blocks MAY narrow to the open record and leave rows out by a lookup (REQ-SMO-025)

A `tasks` block MUST accept the record scope (`recordField`, `recordKey`) and `lookups` that a `collection` block accepts today (`RecordScopeNormaliser`). It MAY declare `excludeWhen: { lookup, in: [scalars] }`: a row whose value under that lookup's `as` is in the list MUST be left out. An `excludeWhen` naming no declared lookup MUST be dropped. An `inbox` block MUST accept `recordField`, keeping only messages whose field holds the open record's id.

#### Scenario: Handed-in work is not a task
- GIVEN a pupil's `tasks` block on assignments with a lookup `as: submission` and `excludeWhen: { lookup: submission, in: [submitted, graded] }`
- AND one of two assignments has a submission in state `submitted`
- WHEN the block renders
- THEN it lists only the other assignment

#### Scenario: The open question of this case only
- GIVEN dossiq's case page with a `tasks` block on `vragenAanU` and `recordField: case`
- AND the resident has open questions on two cases
- WHEN case 2026-0003 is open
- THEN the block lists only the question on 2026-0003

#### Scenario: Messages about the chosen child
- GIVEN the guardian overview with Vera chosen and an `inbox` block with `recordField: learnerRef`
- WHEN the block renders
- THEN it lists no message about Sami

### Requirement: The record switcher MAY take its subtitle from a related record (REQ-SMO-026)

`records` MAY declare `subtitleLookup` with the one-hop lookup shape (`collection`, `matchField`, `valueField`). The switcher MUST show the looked-up value under the title when one is found, and nothing when not. A lookup over two hops is not offered.

#### Scenario: Vera, Groep 6
- GIVEN `records: { collection: parentChildren, subtitleLookup: { collection: parentGroupMemberships, matchField: learnerRef, valueField: cohortName } }`
- WHEN the switcher renders
- THEN Vera's option reads "Vera" with "Groep 6" under it

### Requirement: A text block on a record page MAY be filled from the record (REQ-SMO-027)

A `richText` block on a record page MAY declare `template` instead of `markdown`, with `{field}` placeholders naming projected fields of the record's collection. Values MUST be inserted as plain text, never as markdown or HTML. A sentence whose placeholder has no value MUST be left out, unless the block declares `whenEmpty: { field: text }`, whose text is then used for that sentence. A placeholder naming an unprojected field MUST make the block drop that placeholder's sentence.

#### Scenario: Access without an end date
- GIVEN the assessor's share page with `template: "U heeft toegang tot {expiresAt}."` and `whenEmpty: { expiresAt: "U heeft toegang zonder einddatum." }`
- AND the share has no `expiresAt`
- WHEN the block renders
- THEN it reads "U heeft toegang zonder einddatum."

#### Scenario: A value is not markup
- GIVEN a record whose title is `**Jan**`
- WHEN a template places `{title}`
- THEN the page shows the asterisks as text

### Requirement: A collection block MAY show its rows as cards with a progress figure (REQ-SMO-028)

A `collection` block MAY declare `display: cards` and `progress: { valueField, totalField, label }`, both fields projected. Each card MUST show the row's title, the figure as text ("120 van 400 uur") and a decorative bar. A row with no total MUST show no figure. A `progress` naming an unprojected field MUST be dropped.

#### Scenario: The trainer's students
- GIVEN the trainer's students collection with `progress: { valueField: hoursDone, totalField: hoursRequired, label: "uur" }`
- AND a student with 120 of 400 hours
- WHEN the block renders
- THEN the student's card reads "120 van 400 uur"
