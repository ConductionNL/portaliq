# example-set-lindelaan-related

## ADDED Requirements

### Requirement: The Lindelaan Woo case has sub-cases, a subject case and objects

The Zuiddrecht fragment SHALL give `woo-fietspad` two sub-cases ("Zienswijze Aannemersbedrijf Van Wijk", handled by Lars de Koning, and "Lakken offerte lantaarnpalen", handled by Pieter Jansen, each with a deadline), SHALL name the closed omgevingsvergunning "Aanleg fietspad Lindelaan" as its subject, written before it in the file, and SHALL link it to the cycle path BGT wgl.0505.1234 and the lamp posts LM-0014 and LM-0015.

#### Scenario: The Gerelateerd tab
- **GIVEN** the set is installed
- **WHEN** a handler opens the Lindelaan case's Gerelateerd tab
- **THEN** Gerelateerde zaken SHALL show "Aanleg fietspad Lindelaan" under the subject group, Deelzaken SHALL list the two sub-cases, and Objecten SHALL list the three objects
