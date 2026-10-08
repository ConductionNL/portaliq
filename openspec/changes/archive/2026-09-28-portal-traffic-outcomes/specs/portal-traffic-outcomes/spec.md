# Spec: portal-traffic-outcomes

## ADDED Requirements

### Requirement: Goals MUST be evaluated from the portal's own definitions

A portal names its goals in `traffic.goals[]`, each `{ id, name, type,
match, value? }` with `type` one of `page_reached`, `event`, `download`,
`form_submitted`, `search` and `match` one of `pathPrefix`, `pathEquals`,
`eventName`, `fileExtension`, `formId`, `term`. The collector does not
change: goals are evaluated by the aggregation over the day's sessions. The
rollup carries `goals: [{ id, name, conversions, completions, value }]`,
where `conversions` counts sessions that met the goal at least once,
`completions` counts the matching events, and `value` is the operator's
value times the conversions; and `conversionRate`, the share of sessions
that met any goal.

#### Scenario: A page-reached goal converts once per session

- **WHEN** a portal has a `page_reached` goal on `/contact` and one session views it twice while another never does
- **THEN** the day's rollup carries one conversion and two completions for that goal
- **AND** the Traffic page's Goals widget shows the goal with its conversions

#### Scenario: Each goal type matches its own field

- **WHEN** goals of every type are evaluated against a session
- **THEN** `page_reached` reads the page path, `event` the event name, `download` the downloaded file's extension, `form_submitted` the form id and `search` the search term

@e2e exclude covered by TrafficGoalsTest, one test per type; the seeded portal carries one page-reached goal

#### Scenario: A portal without goals says so and links to its settings

- **WHEN** the selected portal declares no goals
- **THEN** the Goals widget says no goals are defined and links to the portal's detail page

### Requirement: Funnel steps MUST count in order

A portal names funnels in `traffic.funnels[]`, each `{ id, name, steps:
[{ name, match }] }` with the goal's match shape. A session is at step N
only when it matched step N after (by sequence) it matched step N-1. The
rollup carries `funnels: [{ id, name, steps: [{ name, sessions,
dropOff }] }]` where `dropOff` is the share of the previous step's sessions
that did not reach this one.

#### Scenario: Steps are counted in sequence

- **WHEN** one session reaches step 1 then step 2 and another reaches only step 1
- **THEN** the funnel's steps carry sessions 2 and 1, and the second step's drop-off is 0.5
- **AND** the Funnels widget shows both steps with their sessions

#### Scenario: An out-of-order step does not count

- **WHEN** a session matches step 2 before it matches step 1
- **THEN** it counts for step 1 only

@e2e exclude covered by TrafficFunnelsTest (in order, out of order, partial); one browser session cannot be made to arrive out of order on purpose

### Requirement: Form analytics MUST never carry a value

When `form_start`, `form_field` and `form_abandon` are enabled events, the
client reports a form's first interaction, each field left (its id and the
milliseconds it had focus) and a started form that was not submitted when
the page is hidden. The form is identified by `data-portaliq-form` or its
id. What was typed is never read, never sent and never stored: the
validator refuses these events unless enabled and keeps only `formId`,
`fieldId`, `lastFieldId` and `ms` on them. The rollup carries `forms:
[{ formId, starts, submits, abandons, completionRate, fields: [{ fieldId,
avgMs, abandonedHere }] }]`.

#### Scenario: An abandoned form is counted

- **WHEN** a session posts `form_start`, a `form_field` and `form_abandon` for one form
- **THEN** the stored field event carries only the field's id and time
- **AND** the day's rollup lists the form with one start and one abandon

#### Scenario: A field event with an extra parameter is stored without it

- **WHEN** a `form_field` arrives with a `value` parameter beside `fieldId` and `ms`
- **THEN** it is stored without `value`

#### Scenario: The form block's fields are observed

- **WHEN** a visitor focuses and leaves a field of a form the site renders
- **THEN** the client posts `form_start` and `form_field` naming the form and the field, and no value

#### Scenario: Form events are refused unless enabled

- **WHEN** a portal did not enable `form_field` and a client posts one
- **THEN** it is refused as `event-not-enabled` and counted

@e2e exclude covered by TrafficEventValidatorTest; the disabled portal's refusal path is asserted by traffic-analytics.spec.ts for every event name alike

### Requirement: Missing pages MUST be reported by the renderer and listed

The built-in renderer marks its not-found state with
`data-portaliq-status="404"`; an external page may carry the same. The
client then sends `page_not_found` when the portal enabled it. The rollup
carries `notFound: [{ path, hits }]` and the Pages widget lists them under
"Missing pages".

#### Scenario: A missing route sends page_not_found

- **WHEN** a visitor opens a route the site has no page for
- **THEN** the client posts `page_not_found` for that location
- **AND** after aggregation the rollup lists the path and the Traffic page shows it as a missing page

### Requirement: Custom dimensions MUST be declared before they are stored

A portal declares `traffic.customDimensions[]`, each `{ id, name, scope }`
with `scope` one of `session`, `event`. The client accepts
`window.portaliqTraffic.dimension(id, value)` and attaches `cd_<id>`
parameters to what it sends next. The validator strips any `cd_` parameter
whose id the portal did not declare. The rollup carries
`customDimensions: { <id>: { <value>: count } }`, sessions for a session
scoped dimension, events for an event scoped one.

#### Scenario: A declared dimension is stored and an undeclared one is stripped

- **WHEN** an event carries `cd_audience` (declared) and `cd_secret` (not declared)
- **THEN** the stored event carries `cd_audience` and not `cd_secret`
- **AND** the day's rollup counts the declared value and the Dimensions widget lists it

### Requirement: Site search MUST be reported by the built-in search box

The built-in renderer's search block reports a `search` event with the term
and, when the search completed, the result count. The Traffic page lists
the searched terms.

#### Scenario: A completed search reports its term and result count

- **WHEN** a visitor searches in the built-in search block
- **THEN** a `search` event with the term and `results` is posted

@e2e exclude the federated search block needs a publication backend the throwaway instance does not run; the emit is covered by the search-block unit test

#### Scenario: Searched terms are listed

- **WHEN** search events with a term were rolled up
- **THEN** the Sources widget lists the terms with their counts
