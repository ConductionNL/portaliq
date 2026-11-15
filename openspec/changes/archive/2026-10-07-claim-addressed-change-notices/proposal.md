# Proposal: claim-addressed-change-notices

## Why

Ruben asked for the rough patches in the primary-school parent portal to be fixed (2026-10-02). One of them: a guardian books a conference time in the portal (learniq #1614), the teacher acknowledges or declines it, and the guardian hears nothing.

Portaliq's change rule (REQ-NAP-001/002) reaches only the resident whose portal subject reference is on the record. A learniq booking holds the guardian's learniq reference, which the guardian's portal account carries as the claim `claims.learniq.guardianRef`. The bookings collection is read through `scopeClaim` and `via`, so the normaliser drops every rule on it.

## What changes

- A change rule MAY name its recipients by a claim: `"recipients": {"field": "guardianRef", "claim": "guardianRef"}`. The claim is the contributing app's own (a bare name or `<app>.<name>` with the app's id). Such a rule may sit on a `scopeClaim` or `via` collection.
- The listener finds the active portal accounts whose claim of that app holds the record's value at `field` (`PortalAccountsByClaim`), then reads the record as each of them through the collection's own scoped read (`PortalObjectReader::readObject` with its scopeField, scopeClaim, via, filter and fields). Only an account that may read the record gets the inbox message and the dispatch. No value, no message.
- A change rule MAY declare its own text per new value: `"messages": {"acknowledged": {"subject": ..., "body": ...}}`. A text is a string or a language map; the portal's language is used, else English, else the first. Placeholders `{field}` and `{field|datetime}` may only name projected fields and are filled from the record as the resident may read it. A new value without an entry is not reported.
- Delivery is unchanged: the same `portalMessage` inbox write, the same `NotificationDispatchService` dispatch and so the same channel preferences, quiet hours and e-mail text.

## Not changed

- A rule without `recipients` works exactly as before, and is still dropped on a `scopeClaim` or `via` collection.
- No new delivery channel, no new schema property.
- Row actions (another lane's change) are untouched.
