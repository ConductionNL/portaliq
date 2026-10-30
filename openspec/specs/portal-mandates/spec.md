# portal-mandates Specification

## Purpose
Mandates on the portal: how a case list for someone a resident acts for reads the represented party.

## Requirements

### Requirement: A mandated case list reads the party in its typed and its bare form (REQ-MTP-001)
A case list read under a mandate MUST read each collection by the represented party in its typed form (`subject:<ref>` or `kvk:<8 digits>`) and in its bare form. A case found by both reads MUST be listed once.

#### Scenario: Linda sees her father's cases
@e2e exclude PHP: tests/Unit/Service/PortalCaseListReaderTest.php
- GIVEN Henk gave Linda a mandate, and dossiq stored Henk's case with the party `subject:henk`
- WHEN Linda opens "Mijn zaken" acting for Henk
- THEN Henk's case is listed

#### Scenario: A case stored with the bare party still opens
@e2e exclude PHP: tests/Unit/Service/PortalCaseListReaderTest.php
- GIVEN a case written before the typed form, with the party `henk`
- WHEN Linda opens "Mijn zaken" acting for Henk
- THEN that case is listed too, and a case found both ways is listed once
