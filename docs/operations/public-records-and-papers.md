---
title: Voting records and confidential papers
sidebar_label: Voting records and papers
---

# Voting records and confidential papers

A contributing app, such as decidiq, can put two things on your site: how each council member voted, and the confidential papers of a closed session for a named reader.

## How each member voted

The app declares a record list under `publicRecords`. In the page designer, add the block **Openbare overzichten** and give it the app and the list id, for example `decidiq` and `memberVotingRecords`.

- The page shows every public member with role and party and a search field.
- `?record=<id>` shows one member on the same page: the figure cards, the votes as a table, the app's note and a link back.
- A link to a member who is no longer listed says "Dit overzicht bestaat niet (meer)." and answers 404.

The reads are anonymous. Portaliq calls the app's two methods without a subject, keeps at most 500 entries and only the keys the contract names, shows values as plain text, and fetches a record only for an id the list holds. Answers are kept for five minutes.

The Zuiddrecht example site gets the page "Hoe stemden de raadsleden" under Gemeenteraad when decidiq is installed.

## Confidential papers

A collection that declares `documents` shows its papers for an object the signed-in resident is named on. `GET /portal/api/collections/{register}/{schema}/{id}/documents` lists them and `.../documents/{documentId}` streams one.

- A collection above the session's trust, an object outside the reader's scope and an unlisted paper each answer 404.
- The app may name an `opened` method. Portaliq calls it before the first byte with the object id, the document id and the session's reference, trust, identity type and audience. Anything but `true`, or an error, streams nothing and answers 503 "The paper cannot be opened right now".
- The aggregate names the collections dropped for trust alone under `stepUp`, with their label and the trust they need and nothing else.
