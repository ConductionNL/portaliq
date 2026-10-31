## ADDED Requirements

### Requirement: A catalogue entry shows what it costs and what it asks of you (REQ-CSC-001)

The catalogue read SHALL add to each entry the fee its binding's case type declares, as `PortalFee::forBinding()` normalises it, read on the server only. The catalogue block SHALL show that fee under the entry's summary as "Kosten: {amount}" in the portal's language and currency format. An entry whose case type declares no usable fee SHALL show no cost line. When the publication carries `conditions` as a list of non-empty strings, the block SHALL list them under the summary; a `conditions` value of any other shape SHALL be ignored. The fee and the conditions SHALL NOT change which form the entry starts.

#### Scenario: A parking permit with a fee
- **GIVEN** a published entry "Parkeervergunning bewoners" whose binding resolves to a case type declaring a fee of 45.00 EUR
- **WHEN** a visitor opens the catalogue
- **THEN** the entry shows "Kosten: € 45,00" under its summary
- e2e: `tests/e2e/catalogue-entry-shows-costs-and-conditions.spec.ts`

#### Scenario: No fee declared
- **GIVEN** an entry whose case type declares no fee
- **WHEN** the catalogue renders
- **THEN** the entry shows no cost line
- @e2e exclude Rendering rule; pinned by tests/intake-catalogue-costs.spec.mjs

#### Scenario: Conditions as a list
- **GIVEN** a publication with `conditions` ["U woont in de gemeente", "U heeft een auto op uw naam"]
- **WHEN** the catalogue renders
- **THEN** both lines are listed under the entry's summary
- @e2e exclude Rendering rule; pinned by tests/intake-catalogue-costs.spec.mjs
