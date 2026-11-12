## ADDED Requirements

### Requirement: An administrator walks through the setup wizard (REQ-RSP-012)

After installing the app, an administrator SHALL be offered a setup wizard that walks through its steps in order: welcome, the choice to load demo data, loading it, and done. Each step SHALL be saved, so the wizard resumes where it stopped.

#### Scenario: First run with demo data
- **GIVEN** a fresh installation
- **WHEN** an administrator opens the app, chooses to load demo data and finishes the wizard
- **THEN** the demo portal exists and the wizard is no longer offered

#### Scenario: The wizard resumes
- **WHEN** an administrator closes the wizard after the demo data choice and opens the app again
- **THEN** the wizard opens at the step after that choice
