---
title: QR codes for a link field
sidebar_label: QR code columns
---

# QR codes for a link field

A contributing app can show a field as a QR code, so a person who reads the portal on a laptop can open a link on their phone. The case in point is a wallet offer: a wallet app claims it by scanning.

## Declare it

On a collection column set `render: "qr"`. A `link` or `qr` column may also set `linkLabel`, the words of the link, 1 to 60 characters, so the link reads "Add to wallet" rather than a long address.

```json
{ "field": "walletOfferUri", "label": "Wallet", "render": "qr", "linkLabel": "Add to wallet" }
```

## What the resident sees

- **On the record page**: the link in its own words, the code, the address as plain text and the line "Scan deze code met je telefoon".
- **In a table**: the link and a button "Toon QR-code" that opens the code in place. One code is open at a time.

An empty value shows nothing. A value that is not an address shows as plain text, with no code and no link.

## Which values become a code

`http`, `https`, a path on the site (made into a full address on the site's own origin, because a phone camera cannot resolve a path), and the two wallet schemes `openid-credential-offer://` and `openid4vp://`. Anything else, `javascript:` included, is plain text. A `link` column keeps its stricter rule: the wallet schemes are not links in a browser.

## How it is drawn

In the browser, by the portal's own encoder (`src/site/lib/qr.js`), so no address goes to a QR service and the site carries no extra library. It writes byte mode at error correction level M, versions 1 to 20 (up to 666 bytes); a longer value shows the link only. The code is black on white with a four-module quiet zone in every theme, and has the accessible name "QR-code voor: {link text}".
