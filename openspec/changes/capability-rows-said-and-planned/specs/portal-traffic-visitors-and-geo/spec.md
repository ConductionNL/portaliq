## ADDED Requirements

### Requirement: A returning visitor is known as the same visitor (REQ-RSP-011)

When a portal persists a client id, the visits of one client id SHALL count as one visitor across visits, so the traffic pages can tell new visitors from returning ones. Without a persisted id, every visit SHALL count as a new visitor.

#### Scenario: A visitor comes back the next day
- **GIVEN** a portal that persists a client id, and a visitor who came on Monday
- **WHEN** the same browser visits again on Tuesday
- **THEN** Tuesday counts that visitor as returning, not as new
