# Tasks: link-field-qr-code

## Backend

- [ ] **T1**: `lib/Contribution/CollectionConfigNormaliser.php`: add `qr` to `RENDER_KINDS`; in `normaliseColumn()` keep a trimmed `linkLabel` of 1 to 60 characters on a `link` or `qr` column, drop it otherwise. Add the `@spec` tag of this change.
  - PHPUnit `CollectionConfigNormaliserTest::testAQrColumnKeepsItsLinkLabel`, `::testAMalformedLinkLabelIsDropped`

## Site

- [ ] **T2**: add a QR encoder with an MIT, BSD or Apache-2.0 licence and no runtime dependencies (for example `qrcode-generator`) to `package.json`; note its licence in the PR.
- [ ] **T3**: `src/site/components/collections/cells.js`: `qrHref(value, origin)` returns the address for an `http(s)`, site-relative (made absolute on `origin`), `openid-credential-offer://` or `openid4vp://` value, else `''`. Leave `safeHref()` as it is.
- [ ] **T4**: new `src/site/components/collections/QrCode.vue`: props `value`, `label`, `size`; imports the encoder dynamically; draws an inline SVG with `role="img"`, `aria-label` "QR code for: {label}", level M, a four-module quiet zone, `#000` on a `#fff` rectangle and no theme variables; renders nothing when the value does not encode.
- [ ] **T5**: `DetailCard.vue`: a `qr` field shows `QrCode` (160 px), the link (`linkLabel` or the address, `utrecht-link`), the address as plain text and the caption. `detailFields()` passes `linkLabel` from the column.
- [ ] **T6**: `CollectionTable.vue`: a `qr` cell shows the link and a `utrecht-button--subtle` toggle "Show QR code" / "Hide QR code" with `aria-expanded`; open, it shows `QrCode` (128 px), the address and the caption. A `link` cell uses `linkLabel` for its words when declared.
- [ ] **T7**: strings in `l10n/en.json` and `l10n/nl.json`: "Show QR code" / "Toon QR-code", "Hide QR code" / "Verberg QR-code", "QR code for: {label}" / "QR-code voor: {label}", "Scan this code with your phone" / "Scan deze code met je telefoon".

## Tests

- [ ] **T8**: `tests/site-qr-field.spec.mjs` (add to `check:specs`): a wallet offer is drawn; a refused scheme renders as text; a path is made absolute; an empty value renders nothing; the table opens one code at a time; the code ignores the theme; the code has an accessible name; the code is drawn without a request. Decode the drawn SVG back to text in the first test, so the test proves what the code says, not that an SVG exists.
- [ ] **T9**: mutation check: removing the scheme check, the `aria-label` or the fixed colours makes a test fail.

## Follow-up outside this repo

- [ ] **T10**: learniq: on `participantCertificates`, add the column `{field: "walletOfferUri", label: "Wallet", render: "qr", linkLabel: "Add to wallet"}` (learniq change `credential-wallet-offer-for-the-learner`). Tracked in learniq, not built here.
