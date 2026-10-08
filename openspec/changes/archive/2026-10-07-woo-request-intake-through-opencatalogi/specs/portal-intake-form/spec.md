## ADDED Requirements

### Requirement: A Woo request form is delivered to opencatalogi's intake

A submission from a form whose binding says `deliverTo: wooRequest` MUST be delivered to opencatalogi's Woo intake, which mints the reference and arms the statutory term. It MUST NOT be created as a plain object. The reference page MUST quote a due date only when a term runs.

#### Scenario: the term is armed
- GIVEN a published binding with `deliverTo: wooRequest` and opencatalogi installed
- WHEN the delivery job takes the citizen's submission
- THEN opencatalogi's provider receives the answers and the moment they were sent
- AND the submission is registered with the minted `WOO-` reference and the `dueAt`
- AND the reference page says the request was received under that reference and names the due date
- @e2e exclude delivery runs in a background job between two apps; pinned by `PortalIntakeDeliveryJobTest`, `PortalWooRequestDeliveryTest` and `tests/intake-entry.spec.mjs`

#### Scenario: the term did not start
- GIVEN opencatalogi stored the request but could not arm its term
- WHEN the delivery job takes the submission
- THEN the submission is marked failed with the Woo reference and the reason
- AND the reference page quotes no due date and asks the citizen to get in touch
- @e2e exclude delivery runs in a background job between two apps; pinned by `PortalIntakeDeliveryJobTest`

#### Scenario: opencatalogi is not installed
- GIVEN opencatalogi is not installed
- WHEN the delivery job takes a `wooRequest` submission
- THEN no provider is located, and the submission is marked failed with "opencatalogi is not installed"
- @e2e exclude needs an instance without opencatalogi; pinned by `PortalWooRequestDeliveryTest`
