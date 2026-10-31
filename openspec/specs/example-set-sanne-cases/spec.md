# example-set-sanne-cases Specification

## Purpose
The Zuiddrecht example set: Sanne de Vries's Lindelaan case is the case the boards draw, and each of her cases names her.

## Requirements

### Requirement: Sanne's Lindelaan case is the case the boards draw, and her cases name her

The fragment's Woo case "Verlichting fietspad Lindelaan" SHALL sit in "Beoordeling ontvankelijkheid" with its status
history, carry the portal reference MZ-ZDR-2026-0412, four contact moments (telephone, two through the portal, e-mail),
seven documents of which four are assessed, two advice requests, four tasks (one open) and an archive status still to
archive. Every case of Sanne de Vries SHALL name her contact record (a `brpPerson` found by her BSN) as requester.

#### Scenario: the installed set
- **GIVEN** the Zuiddrecht example set installed
- **THEN** exactly one "Verlichting fietspad Lindelaan" case exists, with Sanne de Vries as requester, its contact tab
  counts 4, its documents read "4 van 7 beoordeeld", and her contact page lists her cases
