# Design: link-field-qr-code

## Context

At portaliq development `412227a4`:

- `lib/Contribution/CollectionConfigNormaliser.php:50`: `RENDER_KINDS = ['text', 'date', 'datetime', 'badge', 'currency', 'boolean', 'link', 'user']`; an unknown kind becomes `text`. `normaliseColumn()` keeps `field`, `label`, `render` and `valueLabels` and drops every other key.
- `src/site/components/collections/CollectionTable.vue:84` draws `render === 'link'` as `<a class="utrecht-link">` with `cellText()` as its words, when `safeHref()` returns an address.
- `src/site/components/collections/DetailCard.vue` draws the record's fields as a description list; `detailFields()` in `cells.js` takes `render` from the column of the same field.
- `cells.js` `safeHref()` passes `http(s)` and site-relative addresses only.
- No QR encoder in `package.json`.

learniq, `openspec/changes/credential-wallet-offer-for-the-learner` (learniq development): `participantCertificates` gets `walletOfferUri`, an `openid-credential-offer://` link that is set while an offer is open and cleared when it is claimed, revoked or fails. Its design D1 keeps the link and draws the code client side; its cross-repo section asks portaliq for this render.

## Screen

No board on the Zuiddrecht canvas (5NkFW28vZUUij43xzxHg5a) draws a QR code or a certificate in the portal. The render follows the house styles that exist: the detail card's description list, the `utrecht-link` of a link cell and the `utrecht-button--subtle` of a table button. Labels:

| Key | English | Dutch |
|---|---|---|
| table toggle, closed | Show QR code | Toon QR-code |
| table toggle, open | Hide QR code | Verberg QR-code |
| accessible name of the code | QR code for: {label} | QR-code voor: {label} |
| caption under the code | Scan this code with your phone | Scan deze code met je telefoon |

`{label}` is the column's `linkLabel`, else its `label`, else the humanised field name.

## Decisions

### D1: A render kind on the column, not a new block

The value is a field of a record the app already projects, and the column vocabulary is how an app says how its fields read (`con-ui-config-v3`). A block would need its own record lookup and scope checks for one field. The detail card already inherits a column's render, so one key covers both the table and the record page.

### D2: The table shows the link and opens the code on request

A code per row makes a table unreadable and gives a camera several codes in view at once. The cell shows the link and a toggle button with `aria-expanded`; the code opens under it. The detail card is about one record, so it shows the code straight away.

### D3: Its own scheme list, not a wider `safeHref()`

`render: link` keeps its rule. A `qr` value is allowed when it is `http(s)`, a site-relative path (made absolute against the site's own address, because a phone camera cannot resolve a path), `openid-credential-offer://` or `openid4vp://`. These two are the EUDI wallet's issuance and presentation schemes, and they do nothing in a browser that has no wallet. The list is a constant in `cells.js` and the same list in the normaliser's documentation; a new scheme is a spec change. A value outside the list renders as plain text, never a code, never a link.

### D4: Drawn in the browser, as SVG

An offer link is a bearer claim on a certificate. Sending it to an online QR service would hand it to a third party. The site encodes it with a small library and draws an inline SVG: error correction level M, a quiet zone of four modules, at least 160 CSS pixels on the detail card and 128 in a table. A value too long for a version 40 code at level M renders as the link only, with no code and no error.

### D5: Fixed black on white, whatever the theme

A scanner needs dark modules on a light field. The SVG uses `#000` modules on a `#fff` rectangle that includes the quiet zone, and theme variables are not applied to it, so dark mode and NL Design System themes cannot invert or tint it. The caption and the link use theme variables and meet AA contrast like the rest of the card.

### D6: Named for a screen reader, with the address as text

The SVG has `role="img"` and an `aria-label` "QR code for: {label}". The link next to it carries `linkLabel` (or the address when there is none). Under it the address is shown as selectable plain text, so a person who cannot scan can read or copy it. An empty value renders nothing: no empty code, no button.

### D7: `linkLabel` for `link` and `qr` columns

learniq wants "Add to wallet", not a 200-character address, as the link's words. The normaliser keeps a trimmed string of 1 to 60 characters on a `link` or `qr` column and drops it on any other kind. It is authored by the app in the reader's language, the same way a column `label` is.

## Risks

- **Licence of the encoder.** Pick one under MIT, BSD or Apache-2.0 and record it in the SBOM. The builder checks it before adding it.
- **Bundle size.** The encoder is loaded with the collection components only (a dynamic import from `QrCode.vue`), so pages without a `qr` column do not pay for it.
