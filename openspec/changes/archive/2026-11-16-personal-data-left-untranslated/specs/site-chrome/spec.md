## ADDED Requirements

### Requirement: Browser translation leaves names and personal data alone (REQ-PDU-001)

The site SHALL mark with `translate="no"` the resident's name, the represented party's name, addresses, e-mail addresses, phone numbers, licence plates and reference numbers wherever it shows them, and SHALL NOT mark the labels and sentences around them. A contribution field marked `personal: true`, or typed as e-mail, telephone or URI, SHALL be marked the same way.

#### Scenario: The greeting
- **WHEN** Sanne Visser opens Mijn Zuiddrecht
- **THEN** "Sanne Visser" sits in an element with `translate="no"` and the greeting word does not

#### Scenario: Her address on Mijn gegevens
- **WHEN** she opens Mijn gegevens
- **THEN** "Lindelaan 12" is marked `translate="no"` and the label "Uw adres" is not

#### Scenario: A personal field of a contribution
- **WHEN** a contribution marks the field `kenteken` as `personal: true`
- **THEN** the case page shows its value marked `translate="no"`
