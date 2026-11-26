# embedded-intake-form Delta: embed-frame-reads-the-referer-origin

## ADDED Requirements

### Requirement: Without an Origin header the Referer's origin is the framing origin (REQ-EIF-006)

A browser sends no `Origin` header on an iframe's document request. The frame route and the frame's submit SHALL take the framing origin from the `Origin` header when the request carries one, and otherwise from the origin (`scheme://host[:port]`) of its `Referer`. The same allowed-origins list SHALL judge either, before any schema is read. A request with neither header, or with a `Referer` that is not an absolute URL, SHALL be refused as a disallowed origin. An `Origin` header that is present SHALL decide on its own. A submission SHALL record the origin only, never the Referer's path or query.

#### Scenario: A frame on an allowed site renders the form
- **GIVEN** a form allowing `https://www.gemeente.nl`
- **WHEN** a page at `https://www.gemeente.nl/loket/verhuizen` frames it and the browser sends a Referer and no Origin
- **THEN** the frame renders the form's fields
- @e2e tests/e2e/embedded-intake-form.spec.ts ("the frame actually renders the form, not just a div for it")

#### Scenario: A frame on another site gets a message
- **GIVEN** the same form
- **WHEN** a page on `https://www.elders.nl` frames it, without Origin
- **THEN** the frame renders a message and no form field, and no schema is read
- @e2e tests/e2e/embedded-intake-form.spec.ts ("a frame on a page of another origin gets words, judged by its Referer")

#### Scenario: No Origin and no Referer serves nobody
- **GIVEN** the same form
- **WHEN** the frame route is requested with neither header
- **THEN** the frame renders a message and no form
- @e2e tests/e2e/embedded-intake-form.spec.ts ("without Origin or Referer the frame serves nobody"); also `PortalEmbedControllerTest::testAFrameWithNeitherOriginNorRefererServesNobody`

#### Scenario: An Origin header wins over the Referer
- **GIVEN** the same form
- **WHEN** a request carries `Origin: https://www.elders.nl` and a Referer on `https://www.gemeente.nl`
- **THEN** the request is refused
- @e2e tests/e2e/embedded-intake-form.spec.ts ("an Origin header that is sent wins over an allowed Referer"); also `PortalEmbedControllerTest::testAnOriginHeaderWinsOverTheReferer`

#### Scenario: A submission records the origin, not the page
- **GIVEN** the same form
- **WHEN** a submission arrives without Origin from a page at `https://www.gemeente.nl/loket/verhuizen?bsn=1`
- **THEN** the case records origin `https://www.gemeente.nl`
- @e2e tests/e2e/embedded-intake-form.spec.ts ("a submission without Origin records the Referer origin, not the page"); also `PortalEmbedControllerTest::testASubmissionWithoutOriginRecordsTheReferersOriginOnly`
