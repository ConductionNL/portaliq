# portal-page-period Delta

## ADDED Requirements

### Requirement: The portal page's traffic figures follow one period selector

The portal detail page SHALL declare one page filter `period` with the options 7 days, 30 days and 12 months (values
`7`, `30`, `365`) and 30 days by default, drawn in the band above the four traffic figures. Each figure SHALL ask the
traffic summary for the days that filter holds (`content.days: "@page.period"`) and SHALL draw no period picker of its
own. A KPI card without `content.days` SHALL keep its own `dateRange` picker.

#### Scenario: choosing 7 days

- **GIVEN** a measured portal's page, opened on 30 days
- **WHEN** the reader chooses 7 days in the band
- **THEN** all four figures ask `/api/traffic/summary` with `days=7`
