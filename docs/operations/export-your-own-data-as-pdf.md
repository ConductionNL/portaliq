---
title: Download your own information as a PDF
sidebar_label: Download as PDF
---

# Download your own information as a PDF

A resident can take a copy of a list or of one record with them, for example payment statements or a budget plan. The app that contributes the collection decides which lists offer it.

## Turning it on

Set `exportPdf: true` on the collection in the contribution. Anything else, or nothing, means the collection offers no export and its export routes answer 404.

Portaliq shows **Download als pdf** above the list and on the detail of one record. The button only appears when OpenRegister can render a PDF from rows it is handed. Without it, the contributions leave `exportPdf` off and a direct request answers 503.

## What is in the file

The file holds what the screen holds and nothing more:

- the rows come from the same read as the list, for the signed-in resident only, with the same branch, case type and waiting-row rules;
- the columns are the collection's own columns and labels, or its projected fields; the record uses its detail fields;
- the title is the label of the collection, the organisation and the date.

A record that is not theirs, or does not exist, is one 404. A resident below the collection's `minTrust` gets 403.

## Limits

Portaliq reads at most 200 rows. If OpenRegister refuses the row count, the resident reads "Deze lijst is te lang voor één pdf. Filter hem eerst." Any other failure reads "De pdf kon niet worden gemaakt. Probeer het later opnieuw."

Each export is recorded in the audit trail as a download with the register, the schema and the record id, or the collection id for a list.

## What it is not

It is a plain table, not a letter or a signed statement. Branded documents belong to document generation. The PDF is the resident's own copy and leaves the system with them.

## What OpenRegister owes

Portaliq calls `ExportService::renderRowsToPdf(string $title, array $columns, array $rows): string`, which renders rows the caller already fetched with the same sandbox and row cap as the existing PDF export. Until that method exists, the export stays off.
