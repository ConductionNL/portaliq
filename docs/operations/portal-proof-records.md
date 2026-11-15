---
title: Proof records in the audit trail
sidebar_label: Proof records
description: Where the portal records who signed in, sent, downloaded or completed something, and how older records moved there
---

# Proof records in the audit trail

When a resident signs in, sends a form, downloads a document or completes a task, the portal records that it happened. These records are the municipality's proof that a message was received or a document was delivered (the burden of proof under the Awb and the WMEBV).

The portal writes them into OpenRegister's audit trail, the same trail every other app uses. Each record is sealed into the trail's hash chain, so a changed or removed record shows.

## What a record holds

- the action, as `portaliq.login`, `portaliq.logout`, `portaliq.refresh`, `portaliq.create`, `portaliq.update`, `portaliq.forward`, `portaliq.download` or `portaliq.complete`
- the resident's portal reference (never a BSN) and organisation
- the session it happened in
- what it happened to: the app, register, schema and id

It never holds what the resident filled in or downloaded.

## Reading the records

An administrator opens OpenRegister's audit trail and filters on an action that starts with `portaliq.`. A record about an object also shows in that object's history. Other accounts cannot read the trail.

The metrics endpoint counts the records per action as `portaliq_audit_entries_total`, without names or ids.

## Records from before this version

Earlier versions kept these records as `portalAuditEntry` objects in the portal's own register. The upgrade moves every one of them into the audit trail with its original time, and removes the old object after its record is written. The upgrade log says how many moved. A record that could not be moved stays where it was and is tried again on the next upgrade.
