# portal-page-traffic

## ADDED Requirements

### Requirement: A page's traffic MUST be counted by its in-site route

The daily page rows SHALL count a page view under its in-site route: the `route` query parameter of the page location when present, else the stored page path. The route SHALL start with a slash and SHALL NOT end with one, except the home route `/`. The page endpoint SHALL normalise a page's `route` by the same rule, so a page and its rows meet on one string.

#### Scenario: The built-in site counts each page by its route

- GIVEN page views on `/index.php/apps/portaliq/site?portal=open-tilburg&route=/contact` and on the same path without a `route`
- WHEN the day is aggregated
- THEN the page rows hold `/contact` and `/`, not the renderer's path

#### Scenario: A trailing slash is one page

- GIVEN page views on `/contact/` and on `/contact`
- WHEN the day is aggregated
- THEN one row `/contact` counts both

### Requirement: Each daily page row MUST carry its sessions, visitors, sources and outbound links

Each row of `portalTrafficDaily.pages` SHALL carry `sessions` (sessions that viewed the page), `visitors` (distinct visitors that viewed it), `engagedSessions` (engaged sessions that viewed it), `referrers` (sessions that entered the portal on this page, by referrer host and channel, top ten) and `outbound` (outbound clicks on this page, by URL, top ten). A roll-up portal SHALL sum these by page, and SHALL leave them out of a page row when a member's row lacked them.

#### Scenario: A page row counts its own sessions and sources

- GIVEN one session entering on `/` from `www.google.com` and moving to `/contact`, and one engaged session viewing `/contact` twice
- WHEN the day is aggregated
- THEN the `/contact` row has 2 sessions, 3 views and 1 engaged session
- AND the `/` row lists `www.google.com` among its referrers with a count of 1

#### Scenario: A roll-up leaves out a figure a member lacks

- GIVEN two member records for one day, one with per-page sessions and one written before them
- WHEN the roll-up portal's day is summed
- THEN the page row they share has views but no `sessions`

### Requirement: Retained raw events MUST be re-aggregated into the new page fields

The aggregation job SHALL, once, re-aggregate every day of every ordinary portal whose raw events are still retained, and then the roll-up portals over those days. It SHALL NOT rewrite a day that has no raw events left, and SHALL NOT rewrite a day whose recomputed record counts fewer events or page views than the stored one. `occ portaliq:traffic:reaggregate` SHALL run the same back-fill on demand.

#### Scenario: A retained day gains the new fields

- GIVEN a stored day without per-page sessions whose raw events are all retained
- WHEN the back-fill runs
- THEN the day's page rows carry `sessions`, `visitors` and `engagedSessions`

#### Scenario: A partly purged day keeps its old row

- GIVEN a stored day of 100 page views of which only 40 raw page views are left
- WHEN the back-fill runs
- THEN the stored day is not rewritten

### Requirement: The page endpoint MUST return one page's figures for a period

`GET /api/traffic/page` SHALL take a portal slug, a page route and a period of 7, 30, 90 or 365 days, empty meaning 30, ending today in UTC. It SHALL fold the portal's "all visits" daily rows for that route and SHALL answer whether the portal is measured. It SHALL return `null`, never zero, for a figure the portal does not measure or no day in the period carries, and SHALL say how many days carried the per-page figures. It SHALL answer 400 with a reason for a malformed slug, route or period, and SHALL stay admin-only.

#### Scenario: A page's figures for the last 30 days

- GIVEN a measured portal with 30 days of rows for `/contact`
- WHEN the page endpoint is asked for `/contact`
- THEN it returns that page's views, sessions, visitors and engaged sessions
- AND its previous pages, next pages, referrers and outbound links

#### Scenario: Days without per-page figures are counted, not zeroed

- GIVEN a period in which only some days carry per-page sessions
- WHEN the page endpoint is asked for it
- THEN `detailDays` is the number of days that did and `recordedDays` the number with a row
- AND a period with no such day returns `sessions: null`

#### Scenario: An unmeasured portal answers null

- GIVEN a portal with measurement off
- WHEN the page endpoint is asked for one of its pages
- THEN `measured` is false and every figure is null

#### Scenario: A bad route is refused

- WHEN the page endpoint is asked for a route that does not start with a slash
- THEN the response is 400 with `invalid-route`

### Requirement: The page detail MUST open with four traffic KPI cards

The page detail page SHALL show page views, sessions, visitors and engaged sessions of that page as four KPI cards above the page details. Each card SHALL carry its own period picker, default the last 30 days, and SHALL link to the Traffic page with the page's portal selected. A card SHALL read "Not measured" when the portal is not measured and "Not available for this period" when no day carries its figure, never a zero.

#### Scenario: A page shows its own last 30 days

- GIVEN a page of a measured portal with rows for its route
- WHEN an admin opens the page's detail page
- THEN four KPI cards show that page's figures from the page endpoint

#### Scenario: A page of an unmeasured portal says so

- GIVEN a page of a portal with measurement off
- WHEN an admin opens the page's detail page
- THEN every card reads "Not measured"

### Requirement: The page detail MUST show where visitors came from and went next

The page detail page SHALL show an Incoming traffic widget (previous pages, entrances, referrer sites and channels) and an Outgoing traffic widget (next pages, exits, outbound links), each for a period of its own. A list whose figures no day in the period carries SHALL say "Not available for this period"; an unmeasured portal SHALL read "Not measured".

#### Scenario: Incoming and outgoing traffic of a page

- GIVEN a page of a measured portal with transitions into and out of its route
- WHEN an admin opens the page's detail page
- THEN Incoming traffic lists the previous pages, entrances and referrers
- AND Outgoing traffic lists the next pages, exits and outbound links
