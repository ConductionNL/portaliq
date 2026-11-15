## ADDED Requirements

### Requirement: The header must carry the search box and one way to the own area

A portal that declares `headerSearch.enabled` or `accountLabel` MUST get the designed header: a
search box with the portal's hint as its visible text and accessible name, submitting to the
portal's search page (`headerSearch.route`, `/zoeken` unless an in-site path is named); while
signed out one button with `accountLabel` that opens `/mijn`; while signed in a chip with the
person's initials, name and organisation linking to the own area, and a sign-out control. On a
phone one menu button MUST open the menu bar and the search box and say whether it is open. A
portal that declares neither MUST keep its header markup unchanged.

#### Scenario: Signed out
@e2e exclude Rendered in node: tests/site-chrome.spec.mjs; live screenshots under the lane folder
- GIVEN a portal with `headerSearch.enabled` and `accountLabel` "Mijn academie"
- WHEN a visitor opens any page signed out
- THEN the header shows a search box named "Zoek een cursus" and one button "Mijn academie"

#### Scenario: Signed in
@e2e exclude Rendered in node: tests/site-chrome.spec.mjs
- GIVEN the same portal and a session for Linda Jansen of Jansen Installatietechniek
- WHEN the header renders
- THEN it shows "LJ", "Linda Jansen", "Jansen Installatietechniek" and a sign-out control, and no account button

#### Scenario: Nothing declared
@e2e exclude Markup compared in node: tests/site-shell-blocks.spec.mjs (baseline header)
- GIVEN a portal without `headerSearch` and `accountLabel`
- WHEN the header renders
- THEN its markup equals the header before this change

#### Scenario: The content API serves the header keys
@e2e exclude PHPUnit tests/Unit/Service/Cms/PortalShellTest.php
- GIVEN a portal record with `headerSearch` and `accountLabel`
- WHEN `/api/content/site` is read
- THEN `headerSearch` has `enabled` true only for a boolean true, and a route that is not an in-site path reads `/zoeken`

### Requirement: The motif must run under the header and over the footer

When the theme hands the site a brand stripe (`--cn-brand-stripe-height`), the site MUST draw it
under the header's menu bar and along the top of the footer. Under the header it MUST draw the
motif image, else one line in the first stripe colour; over the footer the inverse image, else the
image, else the three bands in their ratio. Without a stripe height nothing MUST be drawn. The
current menu item MUST be bold with a bar in `--thematiq-accent-color`.

#### Scenario: A school motif
@e2e exclude Live screenshot per set under the lane folder; CSS contract in tests/site-chrome.spec.mjs
- GIVEN a portal on `wilgenboom`
- WHEN a page renders
- THEN the twigs run under the menu bar and the light twigs over the footer

#### Scenario: Zuiddrecht
@e2e exclude Live screenshot under the lane folder
- GIVEN a portal on `zuiddrecht`
- WHEN a page renders
- THEN a red line runs under the menu bar and red, blue, red bands 6 : 3 : 1 over the footer

### Requirement: The footer must carry the motif, the light logo and the brand column first

A footer that declares `cta` or `contact` MUST read brand column, contact column, menu columns.
The brand column MUST show the set's light logo when the set ships one, the footer text and the
button. A contact line with a followable `href` MUST be a link, any other line plain text. The
bottom band MUST take `--thematiq-footer-legal-background-color` when the theme names it. Any
other footer MUST keep its order.

#### Scenario: A designed footer
@e2e exclude Rendered in node: tests/site-chrome.spec.mjs; PHPUnit PortalShellTest and PortalThemeResolverTest
- GIVEN a portal whose footer has a button and a contact column
- WHEN the footer renders
- THEN the brand column comes first, the contact column second, the menus after them

#### Scenario: An existing footer
@e2e exclude Rendered in node: tests/site-chrome.spec.mjs
- GIVEN a footer without a button or contact column
- WHEN it renders
- THEN the menus come first and the brand column last, as before

### Requirement: The sign-in page must offer each way in as a card for its role

A portal that writes `authentication.modeLabels` or `authentication.signInPage` MUST get a
sign-in page with one card per declared way in, in the order of `modes`: the card's title and
text, one button (the first primary, the others secondary), and its hint; then the notice and the
line for staff; and the side panel when it holds a point. A portal that writes neither MUST keep
the plain list of buttons.

#### Scenario: Two roles
@e2e exclude Rendered in node: tests/site-chrome.spec.mjs; live screenshot under the lane folder
- GIVEN a portal with modes `nextcloud` and `digid`, each with a card
- WHEN a visitor opens `/mijn` signed out
- THEN two cards show, "Ik ben leerling" first with the primary button

#### Scenario: Only modes
@e2e exclude Source contract in tests/site-chrome.spec.mjs; tests/site-auth.spec.mjs keeps the plain routes
- GIVEN a portal with only `modes`
- WHEN a visitor opens `/mijn` signed out
- THEN the page shows "Welkom" and one button per way in, as before

#### Scenario: The content API drops what cannot be offered
@e2e exclude PHPUnit tests/Unit/Service/Cms/PortalShellTest.php
- GIVEN cards for `public` and for a mode with no text
- WHEN `/api/content/site` is read
- THEN neither card is served
