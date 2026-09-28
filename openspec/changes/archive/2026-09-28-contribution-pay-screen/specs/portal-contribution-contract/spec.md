---
status: proposed
---

# Spec: portal-contribution-contract (endpoint row actions and the pay screen)

## MODIFIED Requirements

### Requirement: Server-enforced status transitions

A `type: update` action MAY declare `set` — a map of WHITELISTED field → fixed
value the SERVER applies over the client body (after whitelisting, before the
write). A collection MAY declare `rowActions`: entries resolving by id to a
`type: update` action or to an endpoint row action in the same contribution,
rendered as per-row buttons. An entry MAY be a string id or an object with an
`id`; only the id is read. A collection MAY also declare the singular
`rowAction` string, which SHALL be read as one more entry. An endpoint row
action is an action with a non-empty instance-local `endpoint` that is not a
`create`, `update` or `propose-change` action and that declares `rowField`, a
field name matching `^[a-zA-Z][a-zA-Z0-9_]*$`; an endpoint action without a
well-formed `rowField` SHALL NOT resolve as a row action. The `PATCH` update
endpoint accepts `?action=<id>` to select which update action to apply. The
transition target is tamper-proof (the client can never choose an arbitrary
value); malformed `set`/`rowActions` are dropped fail-closed; ownership
re-verification and scope re-stamp still run.

#### Scenario: A transition target cannot be tampered with

- GIVEN an update action `close` with `fields: [status]` and `set: {status: closed}`
- WHEN the subject PATCHes their own row with `?action=close` and body `{status: "hacked"}`
- THEN the saved `status` is `closed` (server `set` overrides the client) and 200 is returned
- @e2e exclude The attack is a HAND-CRAFTED body — the portal UI never offers a way to send `status: "hacked"`, so driving this through the browser would prove the UI is well behaved, not that the server is. It is an API-level tamper assertion. Pinned by PortalManifestNormaliserTest::testSetKeepsOnlyWhitelistedScalarTransitionValues and the PATCH-path cases in ContributionControllerTest.

#### Scenario: rowActions and set fail closed

- GIVEN `set: {status: closed, subjectRef: other}` and `rowActions: [close, createTicket, ghost]`
- WHEN normalised
- THEN `set` keeps only `{status: closed}` and `rowActions` keeps only `[close]`
- @e2e exclude Normaliser fail-closed contract asserted on the manifest structure — the browser-visible consequence is a button that is not rendered and a field that is not written, both absences. Pinned by PortalManifestNormaliserTest::testRowActionsResolveOnlyToUpdateActionsInContribution and ::testMalformedSetIsDropped.

#### Scenario: An endpoint action with a rowField resolves as a row action

- GIVEN an endpoint action `pay` with `rowField: invoiceId`, and a collection declaring `rowAction: pay` and `rowActions: [{id: close}]`
- WHEN normalised
- THEN the collection's `rowActions` is `[close, pay]` and carries no `rowAction` key
- @e2e exclude Normaliser contract on the manifest structure. Pinned by tests/Unit/Contribution/RowActionResolverTest.php::testSingularRowActionAndObjectEntriesResolve.

#### Scenario: An endpoint action resolves as a row action

- GIVEN an endpoint action `sign` with `rowField: signingRequestId` and `rowActions: [{id: sign}]`
- WHEN normalised
- THEN `rowActions` keeps `sign` with kind `endpoint`
- @e2e exclude Normaliser contract; pinned by PortalManifestNormaliserTest

#### Scenario: An endpoint action without a rowField is not a row action

- GIVEN an endpoint action `pay` with no `rowField`, or with `rowField: "invoice id"`, named by a collection's `rowAction`
- WHEN normalised
- THEN the collection carries no `rowActions` and no `rowAction`, and `pay` stays in the contribution's `actions`
- @e2e exclude The browser-visible consequence is a button that is not rendered, an absence. Pinned by tests/Unit/Contribution/RowActionResolverTest.php::testAnEndpointActionWithoutAWellFormedRowFieldIsNotOffered.

## ADDED Requirements

### Requirement: A row-scoped forward MUST prove the row before it forwards

The portal SHALL serve `POST /portal/api/collections/{register}/{schema}/{id}/actions/{actionId}`
(optional `?collection=` to pick one of several collections on one schema). It
SHALL answer 401 without a subject. It SHALL answer 403, with no read and no
forward, when the collection is not in the subject's own aggregate, when its
normalised `rowActions` does not name `actionId`, when `actionId` is not an
endpoint row action of the same contribution, when the collection's or the
action's `minTrust` is not met, or when the endpoint is not an instance-local
path with an allowed method. It SHALL then read the row with the collection's
own scope (`scopeField`, `scopeClaim`, `via`) and answer a single 404, with no
forward, when the row is not the subject's or does not exist. The forwarded
body SHALL be built from the action's `fields` whitelist only (none declared
means an empty body), SHALL carry the proven row's id under the action's
`rowField` over any client value, and SHALL carry the resolved scope under a
declared `subjectField` (403 with no forward when it does not resolve). The
portal SHALL record one audit entry with verb `forward`, SHALL relay the domain
app's status and JSON body, and SHALL answer 502 on a transport failure.

#### Scenario: The row id reaching the domain app is the row the subject owns

- GIVEN the parent collection `salesInvoices` scoped by `customerId` on the claim `customerMasterId`, and the endpoint row action `pay` with `rowField: invoiceId`
- WHEN a guardian posts to the row-scoped forward for their own invoice with body `{invoiceId: "someone-elses", amount: 1}`
- THEN shillinq receives exactly `{invoiceId: "<the proven row id>"}` and the portal relays shillinq's `{checkoutUrl}`
- @e2e exclude The attack is a hand-crafted body; the portal UI sends no body at all. Pinned by tests/Unit/Controller/PortalRowActionControllerTest.php::testTheProvenRowIdIsStampedAndTheClientBodyIsIgnored.

#### Scenario: A row the subject does not own is never forwarded

- GIVEN the same collection and action
- WHEN a guardian posts to the row-scoped forward for an invoice id their scope does not read
- THEN the answer is 404 and no request reaches shillinq
- @e2e exclude An absence of an outbound call for another person's row, which needs a second subject's data to exist; pinned by tests/Unit/Controller/PortalRowActionControllerTest.php::testARowOutsideTheScopeIs404WithoutForwarding.

#### Scenario: An action the collection does not offer on rows is refused

- GIVEN a collection whose `rowActions` does not name `pay`, or a subject below the action's `minTrust`
- WHEN the subject posts to the row-scoped forward for `pay`
- THEN the answer is 403 and no row is read
- @e2e exclude An API-level refusal the UI never triggers; pinned by tests/Unit/Controller/PortalRowActionControllerTest.php::testAnActionTheCollectionDoesNotOfferIs403 and ::testTrustBelowTheActionIs403.

### Requirement: An endpoint row action MUST be offered only on the rows its rowWhen names

An endpoint row action MAY declare `rowWhen`: `{field, in}` with `field` a
field name and `in` a non-empty list of scalar values. A malformed `rowWhen`
SHALL keep the action from resolving as a row action. The portal SHALL show
the row button only on a row whose `field` holds one of the listed values, and
the row-scoped forward SHALL answer 409 `not_offered`, with no forward, for a
row that does not match, read after the scope check.

#### Scenario: A paid contribution offers no pay button and cannot be paid again

- GIVEN `pay` with `rowWhen: {field: state, in: [issued, partially-paid, overdue]}`
- WHEN the guardian's list holds one `issued` and one `paid` contribution, and the guardian posts to the row-scoped forward for the paid one
- THEN only the issued row shows the button, and the forward answers 409 with no request to shillinq
- @e2e exclude The UI half is asserted by tests/row-action.spec.mjs (offersRowAction and the rendered table); the API half by tests/Unit/Controller/PortalRowActionControllerTest.php::testARowOutsideRowWhenIs409WithoutForwarding. No leaf app declares rowWhen until shillinq's follow-up lands.

### Requirement: A collection MUST be able to name a notice field

A collection MAY declare `noticeField`, a field name. A malformed value SHALL
be dropped. When the selected row carries non-empty text in that field, the
portal SHALL show it as a notice above the detail fields and in the confirm
step of a row action; a row without it SHALL show no notice.

#### Scenario: A voluntary contribution says so before the guardian pays

- GIVEN the parent `salesInvoices` collection with `noticeField: invoiceNote`, and a voluntary contribution whose `invoiceNote` reads "This contribution is voluntary. Your child takes part whether you pay or not."
- WHEN the guardian opens it, and when the guardian presses its pay button
- THEN the sentence is shown as a notice in the detail card and in the confirm step
- AND a contribution that is not voluntary shows no notice
- @e2e exclude Rendered markup asserted by tests/row-action.spec.mjs::the confirm step shows the notice on a voluntary contribution, and none otherwise (the detail card renders the same rowNotice value); the normaliser half by tests/Unit/Contribution/RowActionResolverTest.php::testNoticeFieldIsKeptOnlyWhenWellFormed.

### Requirement: The portal MUST let a guardian pay a school contribution from its row

For an endpoint row action, the portal SHALL ask the subject to confirm first,
showing the action's label and the row's notice. On confirm it SHALL post to
the row-scoped forward with no body. When the answer is 2xx and carries a
`redirectUrl` or `checkoutUrl` that is an absolute `https:` URL, the portal
SHALL send the browser there; any other URL SHALL NOT be followed and the
portal SHALL say the next page could not be opened. A 2xx without a URL SHALL
show that it worked and reload the collection. A 503 or 502 SHALL say the step
is not available now; a 403, 404 or 409 SHALL say it can no longer be done for
this item. Portaliq SHALL NOT read, compute or send an amount.

#### Scenario: A guardian goes to the checkout for a contribution

- GIVEN a guardian with audience `parent`, an issued school contribution, and shillinq answering `{checkoutUrl: "https://pay.example.nl/checkout/abc"}`
- WHEN the guardian presses the row's pay button and confirms
- THEN the browser goes to `https://pay.example.nl/checkout/abc`
- @e2e exclude Needs shillinq's rowField follow-up and a bound payment provider; the decision is a pure function asserted by tests/row-action.spec.mjs::redirectTarget follows only an https URL from a 2xx answer.

#### Scenario: A checkout URL that is not https is not followed

- GIVEN a 2xx answer carrying `checkoutUrl: "javascript:alert(1)"` or `"http://pay.example.nl"`
- WHEN the portal handles the answer
- THEN the browser stays on the portal and the guardian reads that the next page could not be opened
- @e2e exclude A hostile answer no real receiver sends; asserted by tests/row-action.spec.mjs::redirectTarget refuses anything but https.

#### Scenario: Online payment is switched off at the school

- GIVEN shillinq answers 503 `{status: "deferred"}` because no payment provider is bound
- WHEN the guardian confirms
- THEN the guardian reads that the step is not available now and stays on the list
- @e2e exclude A provider state the test instance cannot switch; asserted by tests/row-action.spec.mjs::outcome maps each status to one message.
