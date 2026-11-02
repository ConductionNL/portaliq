# Proposal: receipt-date-reads-in-the-portals-language

## Why

Rehearsal R3, 16 October: the second receipt in the portal inbox (the WMEBV ontvangstbevestiging with its WMEBV reference) said "Wij hebben uw indiening ontvangen op 2026-10-16T07:41:12+00:00". The body put the stored ISO moment into the sentence as it is.

## What changes

- The receipt body names the moment in the portal's language and the instance's time zone, the day in words and the time to the minute ("16 oktober 2026 09:41").
- The stored `receivedAt` and the proof log's `submittedAt` stay ISO 8601.

## Rollback

Revert the commit.
