---
kind: code
---

# Proposal: portal-domains-and-editors

## Why

The board **PtPortalInstellingen** puts a portal's settings on one tab: its details, its house style, its domains with their verification and certificate, sign-in, registration, and the people who edit it. Today a beheerder cannot see a domain's state in the app, cannot add a domain, and cannot see when its certificate ends. The domain check exists (`/api/cms/domains` publishes the TXT record and `verify` checks it) but only the site editor bundle calls it. Nobody can see who edits a portal.

## What changes

- **Domains on the settings tab.** A card "Domeinen" lists the portal's domains with status (Geverifieerd, Wacht op verificatie), the certificate end date, the TXT record a pending domain must publish, "Opnieuw controleren" and "Domein toevoegen". It reuses the existing verification; no new way of proving control.
- **Certificate expiry.** A domain gains `certificateValidUntil` and `certificateCheckedAt`. A check reads the end date from the certificate the host presents on port 443, so the card can say "geldig tot 14 jan 2027" and warn before it ends.
- **Editors per portal.** A portal gains `editors`, a list of `{userId, displayName, role}` with the roles `chief-editor` (Hoofdredacteur) and `editor` (Redacteur). The card "Beheerders" in the side column lists them and the settings tab edits them.
- **Details of the portal** (name, slogan, address, organisation, kind, header, languages, login button, logo, embed origins) become a form on the settings tab, on fields the portal already has.

## Out of scope

- **Editors do not gain rights.** Who may change pages stays the group setting "Paginaredacteuren" (and administrators). `editors` is the list the portal shows of who looks after it; a per-portal permission check is a separate change. The card says so, so nobody reads the list as an access list.
- A background job that re-checks domains. A check runs when a beheerder asks for it. The existing note on `verifiedAt` (re-checked periodically) is not delivered here.
- Issuing or renewing certificates. portaliq only reads the end date.
