# Proposal: link-field-qr-code

## Why

learniq's change `credential-wallet-offer-for-the-learner` (merged on learniq development, commit 146c60a4) keeps the wallet offer link of a certificate in `Credential.walletOfferUri` and puts it in the participant portal's "My certificates" collection. A wallet app claims that offer by scanning a QR code, and the wallet usually lives on a phone while the participant reads the portal on a laptop. The portal can only show a link (`render: link`), and that link is refused anyway: `safeHref()` in `src/site/components/collections/cells.js` passes `http(s)` and site-relative addresses only, and an offer link starts with `openid-credential-offer://`. learniq's design names the gap as its cross-repo item: "a block or column render that draws a QR code from a link field".

## What changes

- **A column may render as a QR code.** `render: qr` joins the column render kinds in `CollectionConfigNormaliser`. The site draws the value as a QR code with the address as a link next to it. The detail card (the record page) shows the code in full; a table cell shows the link and a "Show QR code" button that opens the code in place, so a table of ten certificates does not become ten codes.
- **A link may carry its own words.** A `link` or `qr` column MAY declare `linkLabel` (at most 60 characters), so learniq's link reads "Add to wallet" instead of a long `openid-credential-offer://` address. The address stays visible as plain text under the code, so a person can read or copy it.
- **A short list of schemes for a QR value.** `http`, `https`, a site-relative path (turned into a full address for the code), and `openid-credential-offer` and `openid4vp`, the two EUDI wallet schemes. Anything else, `javascript:` included, renders as plain text with no code and no link.
- **The code is drawn in the browser.** No address leaves the page to a QR service: an offer link is a one-time claim on someone's certificate.
- **Readable and named.** Dark modules on a fixed white field with a quiet zone, in light and dark theme alike; the code has an accessible name that says what it opens; the link and caption meet WCAG 2.2 AA.

## Capabilities

### Modified capabilities

- `portal-contribution-contract`: ADDED requirements for the `qr` render kind, `linkLabel`, the QR address schemes, and how the code is drawn.

### Capability row

No row in `openspec/parity/capabilities.json` names a QR render. The closest is `con-ui-config-v3` ("Let a contributing app control how its own data renders"), which is built; this change extends its vocabulary and does not reopen the row. No row is added or relinked.

## Impact

- **Backend**: `lib/Contribution/CollectionConfigNormaliser.php` (`RENDER_KINDS`, `normaliseColumn` keeps `linkLabel`).
- **Site**: `src/site/components/collections/cells.js` (`qrHref`), a new `src/site/components/collections/QrCode.vue`, `CollectionTable.vue`, `DetailCard.vue`, `l10n` strings.
- **Dependency**: one small QR encoder with a licence compatible with EUPL-1.2 (for example `qrcode-generator`, MIT, no dependencies).
- **Consumers**: learniq sets `render: qr` and `linkLabel: "Add to wallet"` on the `walletOfferUri` column of `participantCertificates` once this lands. Until then learniq shows a link, which works on the phone the wallet is on.

## Not in this change

- A QR code on a page block or a CMS widget (`nlQrCode`). Only collection columns and the detail card.
- The `display: rows` list (`ItemList.vue`), which renders title, subtitle and status fields, not columns.
- Widening `safeHref()` for `render: link`. The wallet schemes are allowed for `qr` only, where the code exists to hand the link to another device.
- Starting a wallet offer from the portal. learniq's design D2 keeps that with staff.
