# example-site

## ADDED Requirements

### Requirement: The example site shows the status of the services

The Zuiddrecht example site SHALL have a published `/status` page, needing
opencatalogi, whose public table reads opencatalogi's `serviceStatus` kind with
the columns Dienst, Toelichting and Status, and the footer menu "Over deze
website" SHALL link to it.

#### Scenario: A visitor checks whether the Woo request form works

- **GIVEN** opencatalogi reports "Woo-verzoek indienen" as Verstoring
- **WHEN** a visitor opens /status
- **THEN** the table lists "Woo-verzoek indienen" with its explanation and "Verstoring"
