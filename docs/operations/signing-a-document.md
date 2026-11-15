---
title: Signing a document in the portal
sidebar_label: Signing a document
description: What a contribution declares so a resident can read, sign or decline a document from its row
---

# Signing a document in the portal

A resident opens the list of documents waiting for their signature, reads the document, ticks that they read it and signs. Or they decline and say why. The portal shows the document and forwards the act. The app that owns the signing request checks the signer, records the signature and keeps the evidence.

## What the resident sees

- **Sign** opens a dialog with the document and a download link. The sign button stays disabled until they tick "I have read this document and I sign it." After signing the portal says "You signed" with the document's name.
- **Decline to sign** asks "Why do you decline?" and sends the reason.
- When the portal cannot show the document, the dialog says so and offers no sign button. Nobody signs what they could not read.
- A refusal from the signing app keeps the dialog open and says the act can no longer be done for this item.

## What a contribution declares

On the collection that lists the requests, three endpoint row actions, each with `rowField` naming the request id the receiver reads and, where the receiver identifies the signer from the signed assertion, `scopeClaim`:

| Action id | Sends | Needs |
|---|---|---|
| `sign` | `{consent: true}` | `fields: ["consent"]` so the portal passes the consent on |
| `decline` | `{reason}` | `fields: ["reason"]` so the portal passes the reason on |
| `viewDocument` | nothing | answers `{documentName, mimeType, contentBase64}` |

The portal keeps only the fields an action declares, and it stamps the row id itself under `rowField`, after reading the row under the resident's own scope. A PDF or an image is shown in the page; anything else, or a document above 8 MB, is offered as a download only.

Next: check your contribution declares all three actions this way, then sign a test request from a portal account at the trust level the actions ask for.
