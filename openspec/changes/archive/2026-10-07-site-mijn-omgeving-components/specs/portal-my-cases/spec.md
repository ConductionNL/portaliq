## ADDED Requirements

### Requirement: My cases MUST name each case's type (REQ-SMO-030)

For a `cases` collection that declares `caseTypeSource`, the case list MUST stamp the case type's name on each own and mandated row as `_caseTypeName`, read once per list. "Mijn zaken" MUST show it with the case. A row whose type does not resolve MUST show no type, and MUST NOT show the type's id.

#### Scenario: A Woo request names its type
- GIVEN dossiq's `mijnZaken` declares `caseTypeSource` and a case of type "Woo-verzoek"
- WHEN the resident opens "Mijn zaken"
- THEN the case shows "Woo-verzoek" and no uuid

#### Scenario: An unknown type stays silent
- GIVEN a case whose type id is not in the case type source
- WHEN "Mijn zaken" renders
- THEN the case shows no type
