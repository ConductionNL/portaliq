# site-portal-parity Delta: date-times-read-without-seconds

## ADDED Requirements

### Requirement: A date and time reads to the minute

A `datetime` cell in a collection and a date on an inbox message SHALL read as the day and the time to the minute in the page's language, without seconds.

#### Scenario: A meeting on the Vergaderkalender
@e2e exclude formatting rule; covered by tests/date-times-to-the-minute.spec.mjs
- **GIVEN** a meeting at 19:30 on 24 September 2026
- **WHEN** a resident reads the Vergaderkalender in Dutch
- **THEN** the date column reads "24-9-2026, 19:30"
