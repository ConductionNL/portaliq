---
kind: code
---

# Proposal: write-record-names-the-mandate

## Why

A case app that tells the represented person what was done for them needs the mandate's name. dossiq change `site-business-and-authorisation` (REQ-SBA-004) writes "Namens {party}" on the case history for a portal write made under a mandate. The write record and `PortalClientWriteEvent::getMandate()` carry `actingFor` (the entity) and `mandate` (the id), but not the label the mandate holder and the represented person know it by, so the case app can only show a raw `kvk:` or `subject:` reference.

## What changes

- When a write runs under a mandate, the write record's `mandate` block also carries `actingForLabel`: the mandate's own `label` (as `PortalMandateService::describe()` answers it).
- Nothing else changes: no new claim on the assertion, no change for writes without a mandate.

## Capabilities

- Modified: `portal-visibility-and-the-party-tree`.
