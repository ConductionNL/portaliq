# portal-traffic-analytics

## MODIFIED Requirements

### Requirement: The Traffic page MUST show what was measured, and say when it was not

The Traffic page SHALL show a measured portal's figures, and SHALL say so when a portal is not measured or has no figures yet, in two different states. Neither state SHALL render a chart. Where the page used to list the top transitions, it SHALL show the path explorer of portal-traffic-path-explorer.

#### Scenario: Measurement is disabled for a portal

- **WHEN** a portal has no measurement enabled
- **THEN** the Traffic page says so for that portal
- **AND** it does NOT render an empty chart

#### Scenario: A measured portal without figures is not the same as an unmeasured one

- **WHEN** a portal measures traffic but the aggregation job has written no daily record for the range
- **THEN** the Traffic page says no traffic was recorded yet, in different words and a different element than "not measured"
- **AND** neither state renders a chart

#### Scenario: The page shows the numbers for a measured portal

- **WHEN** a portal measures traffic and a daily record exists for the range
- **THEN** the Traffic page shows page views, sessions, visitors and engaged sessions for the last 30 days, a chart per day, the top pages with entrances and exits, the path explorer, and the sources by channel
- **AND** switching the portal selector to another portal switches every widget on the page
