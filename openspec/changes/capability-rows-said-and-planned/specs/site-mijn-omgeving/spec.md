## ADDED Requirements

### Requirement: The overview shows running cases and the newest messages (REQ-RSP-014)

When no contribution marks its own home page, the resident's overview SHALL show, below what they still have to do, their running cases from every cases collection and their newest messages.

#### Scenario: A resident with cases and messages
- **GIVEN** a resident with one open task, two running cases and three messages
- **WHEN** they sign in
- **THEN** the overview shows the task first, then the two running cases, then the newest messages
