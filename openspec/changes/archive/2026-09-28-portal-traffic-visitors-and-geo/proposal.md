---
kind: code
---

# Proposal: portal-traffic-visitors-and-geo

## Summary

Phase 1 of portal traffic analytics (phase 0: `portal-traffic-analytics`).
Three things the phase 0 collector deliberately left as stubs or as
"later", each now delivered without changing the privacy posture:

1. **Geography.** Phase 0 shipped `GeoResolverInterface` with a resolver
   that answers nothing, so every portal's `region` was empty whatever it
   asked for. This change adds an offline lookup: an MMDB file read with the
   `maxmind-db/reader` package, fetched from DB-IP's free city database by
   default (CC BY 4.0, attribution kept with the file) or from MaxMind when
   the operator has an account. The file lives in the app's data directory,
   never in the app source. The address still never leaves the request: it
   is looked up, the country or the subdivision is kept at the granularity
   the portal chose, and it is discarded.
2. **Visitors, said honestly.** A cookieless portal cannot tell a returning
   visitor from a new one, because the visitor hash does not survive the
   day. Phase 0 reported every cookieless visitor as new, which is a number
   that looks like a finding. Now `returningVisitors` is `null` in cookieless
   mode, never zero, and counted only for a portal that persists a client id.
   When a portal switched on account linking, the signed in portal user's
   pseudonymous reference is attached and the day counts distinct accounts.
3. **The page.** A date range shared by every Traffic widget (last 7, 30
   or 90 days, or a custom range), a Visitors widget with the device,
   browser, operating system, language and region breakdowns, and the
   Reports card that opens the page.

## Decisions carried (Ruben, 2026-09-04)

- Decision 2: cookieless by default. New versus returning is only known
  where the portal persists a client id, and the rollup says "not
  available" elsewhere.
- Decision 6: account linking is settings gated per portal and attaches a
  pseudonymous reference (the portal account's `subjectRef`), never a BSN,
  a KVK number or an email address.
- Decision 7: DB-IP Lite is the default provider; MaxMind is optional
  through an account id and a licence key.

## Affected Projects

- [x] `portaliq`: the resolver, the providers, the refresh command and job,
      the settings, the rollup fields, the client hint, the widgets, the docs.
- [ ] `docusaurus-plugin-portaliq`: no change; the served client is the
      same file.

## Out of scope

- City level geography. The contract stops at the subdivision, and the
  databases fetched here are used no finer than that.
- Funnels, goals, form analytics (phase 3), heatmaps and session recording
  (phase 4).
