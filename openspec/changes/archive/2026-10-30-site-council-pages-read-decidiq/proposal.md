---
kind: code
depends_on:
  - site-member-voting-record-and-confidential-papers
---

# Proposal: site-council-pages-read-decidiq

## Summary

The Zuiddrecht example site's council pages were static text: `/bestuur/vergaderingen` listed no meeting, and no page showed a decision, while decidiq held six meetings and seven decisions. decidiq now offers its published meetings and decisions as public record lists (`publicMeetings`, `publicDecisions`, decidiq change `portal-public-council-records`), next to `memberVotingRecords`. This change places them on the site, as page configuration over the existing public records block.

## What changes

- `/bestuur/vergaderingen` gets a public records block on `decidiq:publicMeetings` ("Zoek een vergadering", back link "Alle vergaderingen") and links to the decisions and the votes.
- A new page `/bestuur/besluiten` ("Besluiten van raad en college") with a block on `decidiq:publicDecisions`. Linked from `/bestuur` (Gemeenteraad), `/bestuur/vergaderingen` and `/bestuur/collegebesluiten`.
- `/bestuur/hoe-stemden-de-raadsleden` gets "Zoek een raadslid" as its search label.
- Every new block, page and link carries `requiresApp: decidiq`: without decidiq the meetings page keeps its text and the decisions page is not installed.
- `PublicRecordsBlock` gets a `searchLabel` prop, because its field said "Zoek een naam" whatever the list held.

One block per page, because every block on a page reads the same `?record=` parameter.

## Screens

No board draws a public meetings or decisions page. Both follow the boards `site-member-voting-record-and-confidential-papers` composed from: **Contentpagina** for the page frame and **Publicatie** for the record.

## Rollout

The example site installer never changes an object that exists. On an instance with the site installed, run `occ portaliq:example-site:remove zuiddrecht` and then `occ portaliq:example-site:install zuiddrecht`.
