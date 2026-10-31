# example-set-woo-case-parties Specification

## Purpose
The Zuiddrecht example set: the parties of the Lindelaan Woo case.

## Requirements

### Requirement: The Lindelaan Woo case carries its parties

The Zuiddrecht fragment SHALL put four parties on `woo-fietspad`, each as a dossiq role record in the Woo case type's role and as a party link with its period:
- Sanne de Vries as primary Verzoeker;
- Aannemersbedrijf Van Wijk B.V. as Belanghebbende;
- J. van Wijk as Gemachtigde acting for Van Wijk, with an end date;
- Bewonersvereniging Lindelaan as Melder.

#### Scenario: The people-involved tab
- **GIVEN** the set is installed
- **WHEN** a handler opens the Lindelaan case's people
- **THEN** the Roles section SHALL list the four parties by role, Sanne first as primary, J. van Wijk with his mandate's end date and the residents' association with its indicator
