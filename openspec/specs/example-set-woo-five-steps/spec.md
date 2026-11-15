# example-set-woo-five-steps Specification

## Purpose
The Zuiddrecht example set: the Woo cases are on the five-step version of the Woo case type.

## Requirements

### Requirement: The Zuiddrecht Woo cases are on the five-step version

Every Woo case of the Zuiddrecht fragment SHALL look up version 2 of the Woo request case type and a status among its five steps, so each lookup finds exactly one row and the case shows the steps the boards draw.

#### Scenario: The Lindelaan case
- **GIVEN** the set is installed on a dossiq with version 2
- **WHEN** a handler opens the Lindelaan Woo case
- **THEN** its progress reads Ontvangen, then In behandeling as the current step
