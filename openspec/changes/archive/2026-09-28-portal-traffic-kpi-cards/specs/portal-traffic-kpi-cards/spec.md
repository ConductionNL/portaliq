# portal-traffic-kpi-cards

## ADDED Requirements

### Requirement: The portal page MUST open with four traffic KPI cards

The portal detail page SHALL show page views, sessions, visitors and engaged sessions as four KPI cards above the portal details. Each card SHALL carry its own period picker, default the last 30 days, and SHALL link to the Traffic page with the portal selected. A portal whose `traffic.enabled` is not true SHALL read "Not measured" on every card and SHALL NOT request its figures.

#### Scenario: A measured portal shows its last 30 days

- GIVEN a portal with `traffic.enabled: true` and daily records for the last 30 days
- WHEN an admin opens the portal's detail page
- THEN four KPI cards show the page views, sessions, visitors and engaged sessions of those 30 days
- AND the figures equal the Traffic page's for the same portal and period

#### Scenario: A card's period picker changes only that card

- GIVEN the portal detail page with its four cards
- WHEN the admin picks "Last 7 days" on the page views card
- THEN that card shows the page views of the last 7 days
- AND the other three cards keep their own period

#### Scenario: An unmeasured portal says so

- GIVEN a portal with measurement off
- WHEN an admin opens its detail page
- THEN every card reads "Not measured"
- AND no summary request is made

#### Scenario: A card opens the Traffic page on this portal

- GIVEN the portal detail page of portal `open-tilburg`
- WHEN the admin clicks a card
- THEN the Traffic page opens with `open-tilburg` selected

### Requirement: The Traffic page MUST show its four headline numbers as KPI cards

The Traffic page SHALL show page views, sessions, visitors and engaged sessions as four KPI cards below the overview card. The cards SHALL follow the portal, period and segment chosen on the overview, and SHALL read "Not measured" for a portal that is not measured and "No traffic recorded yet" for a measured portal without records, never a zero. The Traffic page SHALL select the portal named in its `portal` query parameter when that portal exists.

#### Scenario: The cards follow the overview's selectors

- GIVEN the Traffic page with a measured portal selected
- WHEN the reader picks another period or segment on the overview
- THEN all four cards show the figures of that period and segment

#### Scenario: The query parameter selects the portal

- GIVEN portals `open-tilburg` and `open-breda`, both measured
- WHEN the reader opens `/traffic?portal=open-breda`
- THEN `open-breda` is selected

### Requirement: The summary endpoint MUST return a portal's four totals for a period

`GET /api/traffic/summary` SHALL take a portal slug and a period of 7, 30, 90 or 365 days, empty meaning 30, ending today in UTC. It SHALL fold the portal's "all visits" daily records with the same arithmetic as the scheduled report, and SHALL answer 400 with a reason for a malformed slug or an unknown period. It SHALL stay admin-only.

#### Scenario: The totals exclude segment rows

- GIVEN a portal day with an "all visits" record of 10 page views and a segment record of 4
- WHEN the summary is requested for that portal
- THEN `pageViews` is 10

#### Scenario: A bad period is refused

- WHEN the summary is requested with `days=12`
- THEN the response is 400 with `invalid-period`
