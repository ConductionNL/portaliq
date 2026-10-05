---
kind: code
depends_on: []
---

# Proposal: publication-error-reports-and-withheld-notices

Woo capability programme, round 1, wave 2. Rows 6.15 and 6.16.

| row | text | our rating today |
| --- | --- | --- |
| 6.15 | A citizen reports an error in a publication, and it reaches someone | partial (build) |
| 6.16 | The portal shows that a withheld record exists, and why it is withheld | partial (production) |

Implements Ruben's decision **D9**: both are policy choices an organisation makes, so both are opt-in
per organisation and off by default. An anonymous channel for reporting an error needs throttling and
a named owner; publishing that a withheld record exists is itself a disclosure choice.

## Why

What portaliq does today, read on `development` at ca591037:

- A visitor on a publication page can download its documents. There is no way to say "this document
  is the wrong version" or "this name should have been redacted". portaliq has two channels that come
  close and do not fit: the change proposal queue (`change-proposal-queue`) proposes values for named
  properties of a record the proposer can read, and a report without an account
  (`report-without-an-account`) is a case intake with a receipt code. Neither reaches the person who
  publishes.
- A withdrawn publication's link renders the site's not-found page. opencatalogi's planned
  `publication-withdrawal-aftercare` (REQ-PWA-002) answers such a link with 410 and
  `{id, withdrawnAt, publicReason, title?}`; the portal does not read it. Documents held back from a
  publication are not mentioned on its page at all.

## What changes

1. **Error reports, opt-in** (6.15): a portal setting `publicationErrorReports` (off by default) and an
   owner, `publicationErrorReportOwner` (a Nextcloud user or group), without which the setting cannot
   be switched on. When on, each publication page offers "Fout melden": a short form (what is wrong,
   which document, optionally an e-mail address for a reply). The report is stored as a
   `publicationErrorReport`, the owner gets a Nextcloud notification, and the report sits in a queue
   in the portal admin where the owner marks it handled or not applicable. The route is public,
   rate limited per address and per publication, and stores no IP address.
2. **Withheld notices, opt-in** (6.16): a portal setting `showWithheldNotices` (off by default). When
   on, a withdrawn publication's link shows a page about the withdrawal: the date, the public reason,
   and the title when the officer allowed it, from opencatalogi's 410 answer. And a publication page
   lists the documents held back from it with their refusal ground, when the public read carries
   them. When off, both stay as today: not found, and no list.

## What does not change

- The change proposal queue and the report without an account.
- What opencatalogi publishes or withholds, and what its tombstone says.

## Dependencies

- Planned, opencatalogi, wave 1: `publication-detail-for-the-portal` (the public read this page uses).
- Planned, opencatalogi, wave 2: `publication-withdrawal-aftercare`, REQ-PWA-002 (the 410 answer with
  `{id, withdrawnAt, publicReason, title?}`). Before it lands, the withdrawal half renders not found.
- The list of documents held back needs opencatalogi's public read to carry them with their grounds.
  No planned opencatalogi change names that key yet; task 3.2 says what to do.

**App absent.** Without opencatalogi there are no publications, as today. The grounds' labels are
whatever opencatalogi answers; opencatalogi reads them from dossiq's list or its read-only snapshot
(D3, D12).

## Wave and done

Wave 2. Done means merged on `development` with CI green. 6.15 and 6.16 then read `yes` (build) as
opt-in features, and `production` only once a portaliq store release carries them.
