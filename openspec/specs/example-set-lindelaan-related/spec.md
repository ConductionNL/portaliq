# example-set-lindelaan-related Specification

## Purpose
The Zuiddrecht example set: the Lindelaan Woo case has its sub-cases, a subject case and objects, written without read-only fields.

## Requirements

### Requirement: The Lindelaan Woo case has sub-cases, a subject case and objects

The Zuiddrecht fragment SHALL give `woo-fietspad` two sub-cases ("Zienswijze Aannemersbedrijf Van Wijk", handled by Lars de Koning, and "Lakken offerte lantaarnpalen", handled by Pieter Jansen), SHALL name the closed omgevingsvergunning "Aanleg fietspad Lindelaan" as its subject, written before it in the file, and SHALL link it to the cycle path BGT wgl.0505.1234 and the lamp posts LM-0014 and LM-0015. A sub-case's deadline SHALL be the one dossiq derives; no dossiq case row in the fragment SHALL write a property the dossiq case schema declares readOnly.

#### Scenario: The Gerelateerd tab
- **GIVEN** the set is installed
- **WHEN** a handler opens the Lindelaan case's Gerelateerd tab
- **THEN** Gerelateerde zaken SHALL show "Aanleg fietspad Lindelaan" under the subject group, Deelzaken SHALL list the two sub-cases, and Objecten SHALL list the three objects

#### Scenario: A clean install
- **GIVEN** the Zuiddrecht set
- **WHEN** it is installed
- **THEN** the report SHALL list no sub-case field as written but not kept
