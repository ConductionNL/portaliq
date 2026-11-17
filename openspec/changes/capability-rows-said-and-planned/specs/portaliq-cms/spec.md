## ADDED Requirements

### Requirement: The public content API answers other sites (REQ-RSP-016)

The portal's public content API SHALL answer a request from another site's browser with the cross-origin headers that let that site read the answer. No other route of the app SHALL get those headers.

#### Scenario: A municipal website reads a page
- **WHEN** a script on another site requests a published page from the content API
- **THEN** the answer carries the cross-origin headers and the page
@e2e exclude a second origin is not available in a run; Unit: tests/Unit/Middleware/PublicApiCorsMiddlewareTest.php

#### Scenario: Another route stays closed
- **WHEN** the same script calls a resident's own API route
- **THEN** the answer carries no cross-origin header
@e2e exclude Unit: tests/Unit/Middleware/PublicApiCorsMiddlewareTest.php
