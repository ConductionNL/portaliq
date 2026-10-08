## ADDED Requirements

### Requirement: A column may render its value as a QR code

A collection column MAY declare `render: qr`. The normaliser MUST keep `qr` as a render kind. A `link` or `qr` column MAY declare `linkLabel`; the normaliser MUST keep it trimmed when it is a string of 1 to 60 characters and MUST drop it otherwise, and MUST drop it on a column of any other render kind. The rest of the column MUST be kept either way.

#### Scenario: learniq's wallet column keeps its render and its words
- GIVEN learniq's `participantCertificates` collection declares a column `{field: "walletOfferUri", label: "Wallet", render: "qr", linkLabel: "Add to wallet"}`
- WHEN the manifest is normalised
- THEN the column carries `render: qr` and `linkLabel: "Add to wallet"`
- @e2e exclude server normaliser, covered by PHPUnit `CollectionConfigNormaliserTest::testAQrColumnKeepsItsLinkLabel`

#### Scenario: A malformed or misplaced link label is dropped
- GIVEN a `qr` column with `linkLabel` as an empty string, a number or 61 characters, and a `badge` column with `linkLabel: "Open"`
- WHEN the manifest is normalised
- THEN neither column has `linkLabel` and both keep their field, label and render
- @e2e exclude server normaliser, covered by PHPUnit `CollectionConfigNormaliserTest::testAMalformedLinkLabelIsDropped`

### Requirement: The site only draws a QR code for an allowed address

The site MUST draw a code and a link for a `qr` value only when it is an `http` or `https` address, a site-relative path, an `openid-credential-offer://` address or an `openid4vp://` address. A site-relative path MUST be encoded as the full address on the site's own origin. Any other value MUST render as plain text, with no code and no link. An empty value MUST render nothing.

#### Scenario: A wallet offer is drawn
- GIVEN a certificate row whose `walletOfferUri` is `openid-credential-offer://?credential_offer_uri=https%3A%2F%2Fexample.org%2Foffers%2F42`
- WHEN the participant opens it on the detail card
- THEN the card shows a QR code that encodes exactly that address, and a link "Add to wallet" to it
- @e2e exclude component behaviour, covered by `node --test tests/site-qr-field.spec.mjs` ("a wallet offer is drawn"); the live certificate page by learniq's proof run

#### Scenario: A script address is never drawn
- GIVEN a `qr` value `javascript:alert(1)`
- WHEN the row is rendered
- THEN the cell shows the text and contains no code and no link
- @e2e exclude component behaviour, covered by `node --test tests/site-qr-field.spec.mjs` ("a refused scheme renders as text")

#### Scenario: A path becomes a full address
- GIVEN a site at `https://portaal.example.nl/` and a `qr` value `/zaken/123`
- WHEN the detail card is rendered
- THEN the code encodes `https://portaal.example.nl/zaken/123`
- @e2e exclude component behaviour, covered by `node --test tests/site-qr-field.spec.mjs` ("a path is made absolute")

#### Scenario: No offer, no code
- GIVEN a certificate whose `walletOfferUri` is empty
- WHEN the participant opens "My certificates"
- THEN that row's wallet cell is empty and has no button
- @e2e exclude component behaviour, covered by `node --test tests/site-qr-field.spec.mjs` ("an empty value renders nothing")

### Requirement: The record page shows the code, the table opens it on request

On the detail card a `qr` field MUST show the code, the link and, under them, the address as selectable plain text and the caption "Scan this code with your phone". In a table a `qr` cell MUST show the link and a button "Show QR code" with `aria-expanded`, which opens the code, the address and the caption in the cell and then reads "Hide QR code". The link's words MUST be `linkLabel` when declared, else the address.

#### Scenario: The participant opens the code from the table
- GIVEN "My certificates" as a table with two certificates that have an open offer
- WHEN the participant presses "Show QR code" on the first row
- THEN that row shows its code, its address and the caption, the button reads "Hide QR code" with `aria-expanded="true"`, and the second row shows no code
- @e2e exclude component behaviour, covered by `node --test tests/site-qr-field.spec.mjs` ("the table opens one code at a time"); no contribution on the e2e instance declares a `qr` column until learniq adopts it

### Requirement: The code is readable by a camera and by a screen reader

The code MUST be drawn in the browser as an inline SVG, without sending the value to any other server. It MUST use error correction level M, a quiet zone of four modules, black modules on a white field that includes the quiet zone, in every theme and in dark mode, and at least 160 CSS pixels wide on the detail card and 128 in a table. The SVG MUST have `role="img"` and the accessible name "QR code for: " followed by the column's `linkLabel`, else its `label`, else the field name in words. The caption and the link MUST meet WCAG 2.2 AA contrast through theme variables. A value too long to encode at level M MUST render as the link and the address only.

#### Scenario: Dark mode does not invert the code
- GIVEN the site in dark mode with an NL Design System theme
- WHEN a detail card with a `qr` field is rendered
- THEN the code's field is `#fff` and its modules are `#000`
- @e2e exclude visual property of a component, covered by `node --test tests/site-qr-field.spec.mjs` ("the code ignores the theme")

#### Scenario: A screen reader hears what the code opens
- GIVEN the wallet column with `linkLabel: "Add to wallet"`
- WHEN a screen reader reaches the code
- THEN it announces an image named "QR code for: Add to wallet"
- @e2e exclude component behaviour, covered by `node --test tests/site-qr-field.spec.mjs` ("the code has an accessible name")

#### Scenario: Nothing leaves the page
- GIVEN a detail card with a `qr` field
- WHEN it is rendered
- THEN no network request carries the value
- @e2e exclude component behaviour, covered by `node --test tests/site-qr-field.spec.mjs` ("the code is drawn without a request")
