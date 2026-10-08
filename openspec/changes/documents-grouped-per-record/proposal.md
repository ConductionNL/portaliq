# Proposal: documents-grouped-per-record

## Why

Each school portal has a documents page on the boards (8 October 2026), and the site's documents block draws one flat list of a case's files (`site-mijn-omgeving` REQ-SMO-005):

- [wilgenboom/Documenten](https://identity.conduction.nl/screens/board?id=wilgenboom/Documenten): groups "Groep 7" (Vera), "Groep 4" (Sami) and "Van school" (jaarkalender, schoolgids, the consent for photos "door u ingevuld op 28 augustus 2026"), each row with "Nieuw" and "Downloaden", and a note "Het eerste rapport van dit schooljaar komt op vrijdag 12 februari 2027. U krijgt een bericht als het klaarstaat."
- [vaartveld/Documenten](https://identity.conduction.nl/screens/board?id=vaartveld/Documenten): groups per kind ("Toetsen en examen", "Keuzes en verklaringen") and per school year.
- [esdoornveen/Documenten](https://identity.conduction.nl/screens/board?id=esdoornveen/Documenten): a status pill on agreements ("Ondertekend"), "Document toevoegen", and a note on DUO's diploma register.
- [warmtepompacademie/Documenten](https://identity.conduction.nl/screens/board?id=warmtepompacademie/Documenten): "Alle geldige certificaten downloaden" above a table "Per medewerker".

Lane T's gap list (8 October) names "documents grouped per child plus school papers as portaliq media". The rows come from learniq's `school-documents-on-the-portal`; this change draws them, and adds the school's own papers.

## What Changes

- The documents block MAY declare `groupBy` (a row field, such as `group`); rows then render under a heading per value, in the provider's order, and a block option `extraGroups` adds groups of portaliq `media` for the resident's audience ("Van school": `media` rows whose audience matches the signed-in person).
- A row MAY carry `isNew` (a "Nieuw" badge until opened, the existing `DataBadge`), `status` (a pill with its tone) and `meta` (the provider's line, shown as written).
- The block MAY declare `note` (one sentence under the list, authored) and `bulkDownload` (a button that calls the provider's declared bundle endpoint, "Alle ... downloaden").
- The block MAY declare an `upload` action from the contributing app ("Document toevoegen"), through the existing create action with a file.

## Not in this change

- Sorting inside a group: the provider's order.
- Previewing a PDF in the page.
