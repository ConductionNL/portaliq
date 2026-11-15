# portal-traffic-path-explorer

## ADDED Requirements

### Requirement: The paths endpoint MUST count each visit's own path from the raw events

`GET /api/traffic/paths` SHALL read a portal's raw events for a period, group them into visits with the same sessioniser, inactivity timeout and UTC day cut as the daily figures, and count each visit's own sequence of page views. It SHALL NOT chain the stored page-to-page pairs. Only `page_view` events SHALL become steps. A step SHALL be the page's in-site route by the same rule as the daily figures (portal-page-traffic): the location's `route` parameter when it has one, else the stored path, with no trailing slash. Two page views of the same route in a row SHALL count as one step. The endpoint SHALL stay admin-only, SHALL answer 400 with a reason for malformed input and 404 for an unknown portal, and SHALL send `Cache-Control: private, no-store`.

#### Scenario: A path is what one visit did, not a chain of pairs

- GIVEN one visit `/home`, `/news`, `/contact` and one visit `/about`, `/news`
- WHEN the paths are requested from the start of a visit with three steps
- THEN step 2 lists `/contact` with 1 visit
- AND no visit that started on `/about` reaches `/contact`

#### Scenario: A reload is not a step

- GIVEN one visit with page views `/home`, `/home`, `/news`
- WHEN the paths are requested from the start of a visit
- THEN step 0 is `/home` and step 1 is `/news`
- AND the visit ends at step 1

#### Scenario: A malformed request is refused with a reason

- WHEN the paths are requested with `steps=12` or `mode=sideways`
- THEN the response is 400 with `invalid-steps` or `invalid-mode`

### Requirement: The explorer MUST start from a visit's start or a page, or end at a visit's end or a page

The explorer SHALL offer a starting point, the start of a visit or a page, and read the steps forward from it. It SHALL offer an ending point, the end of a visit or a page, and read the steps backward from it. From a page, a visit SHALL count from its first view of that page; to a page, from its last view of that page. A visit that never viewed the page SHALL NOT count.

#### Scenario: Start from a page

- GIVEN visits `/home`, `/news`, `/contact` and `/news`, `/about` and `/home`, `/about`
- WHEN the reader starts from `/news`
- THEN step 0 is `/news` with 2 visits
- AND step 1 lists `/contact` and `/about` with 1 visit each

#### Scenario: End at a page

- GIVEN visits `/home`, `/news`, `/contact` and `/about`, `/contact`
- WHEN the reader ends at `/contact`
- THEN step 0 is `/contact` with 2 visits
- AND the step before lists `/news` and `/about` with 1 visit each

### Requirement: Each step MUST show its busiest pages, the rest as one node, and where visits ended

Each step SHALL list its five busiest pages by visits, and SHALL sum every other page of that step into one "+N more" node that names how many pages it holds. Each node SHALL carry the visits that ended on it. Each step SHALL carry its total visits and total drop-offs. The links between two steps SHALL count visits, and a link to or from a page in "+N more" SHALL attach to that node.

#### Scenario: The sixth page and beyond become one node

- GIVEN a step on which seven pages have 10, 9, 8, 7, 6, 2 and 1 visits
- WHEN the step is built
- THEN it shows the five pages with 10, 9, 8, 7 and 6 visits
- AND one "+2 more" node with 3 visits

#### Scenario: Drop-offs are counted per node and per step

- GIVEN 3 visits `/home`, `/news` and 2 visits that only viewed `/home`
- WHEN the paths are built from the start of a visit
- THEN `/home` on step 0 has 5 visits and 2 drop-offs
- AND step 0 reads 5 visits and 2 drop-offs

### Requirement: The reader MUST be able to expand from a node and change the number of steps

Choosing a page node SHALL narrow every later step to the visits that passed through it at that step, and SHALL narrow the steps after it by every earlier choice too. Choosing the same node again SHALL undo that choice and the choices after it. The reader SHALL be able to add and remove steps, between one and ten after the start or end point. A "+N more" node SHALL NOT be expandable.

#### Scenario: A chosen node narrows the next steps

- GIVEN visits `/home`, `/news`, `/contact` and `/home`, `/about`, `/faq`
- WHEN the reader chooses `/news` on step 1
- THEN step 2 lists `/contact` with 1 visit and no `/faq`
- AND step 1 still lists both `/news` and `/about`

#### Scenario: Steps can be added and removed

- GIVEN the explorer showing three steps
- WHEN the reader adds a step
- THEN four steps follow the start point
- AND removing a step shows three again

### Requirement: The explorer MUST say when it shows less than the chosen period

Paths SHALL cover only the days whose raw events are still kept. When the chosen period starts before the first kept day, the explorer SHALL say which days the paths cover. One read SHALL scan at most 50,000 events, newest day first; when it stops there, the response SHALL say it is truncated and the explorer SHALL say which days the paths cover and that the oldest of them is incomplete.

#### Scenario: A period beyond retention names the days covered

- GIVEN a portal that keeps raw events for 90 days
- WHEN the reader chooses a custom period of the last 180 days
- THEN the explorer says the paths cover only the last 90 days, with their first and last date

#### Scenario: A capped read says it is truncated

- GIVEN a period with more than 50,000 events
- WHEN the paths are requested
- THEN the response carries `truncated: true` and the first day it read
- AND the explorer says the paths show the most recent visits only

### Requirement: The explorer MUST follow the page's portal, period and segment

The explorer SHALL read the portal, period and segment chosen on the Traffic page overview. A segment SHALL narrow the visits with the same per-visit rule as the segment's daily figures. The explorer SHALL show the page's "Not measured for this portal" and "No traffic recorded yet" states, and SHALL NOT draw an empty diagram for either.

#### Scenario: A segment narrows the paths

- GIVEN a segment "Mobile" matching visits whose device type is `mobile`
- WHEN the reader selects that segment
- THEN the explorer counts only the mobile visits

#### Scenario: An unmeasured portal shows no diagram

- GIVEN a portal with measurement off
- WHEN the reader selects it on the Traffic page
- THEN the explorer reads "Not measured for this portal"
- AND no path diagram is drawn

### Requirement: The explorer MUST be operable by keyboard and readable without the diagram

Every page node SHALL be reachable with Tab, SHALL show a visible focus indicator, and SHALL be chosen with Enter or Space. Each node SHALL announce its page, visits and drop-offs. The same steps SHALL be available as a table with the step, page, visits and drop-offs. Colours SHALL come from the theme's variables so the diagram works in dark mode.

#### Scenario: A node is chosen by keyboard

- GIVEN the explorer with a node for `/news` on step 1
- WHEN the reader tabs to it and presses Enter
- THEN the node is marked as chosen
- AND the next steps are narrowed to its visits

#### Scenario: The steps are available as a table

- GIVEN the explorer showing three steps
- WHEN the reader opens "Show as a table"
- THEN a table lists every node of every step with its visits and drop-offs
