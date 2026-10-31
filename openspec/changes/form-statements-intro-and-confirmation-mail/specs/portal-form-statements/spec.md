## ADDED Requirements

### Requirement: A form can open with an introduction page (REQ-FCI-001)

A binding with `intro` SHALL show an introduction page before step 1 with its lead, its "Voordat u begint" blocks, the ways to continue the binding allows, and "Start". A binding without `intro` SHALL open at step 1.

#### Scenario: Reading before starting
- **WHEN** a resident opens /aanvragen/woo-verzoek on a binding with an introduction
- **THEN** she sees "Voordat u begint" and "Hoe wilt u verdergaan?", and "Start" opens step 1

### Requirement: Required statements are accepted before sending (REQ-FCI-002)

The review step SHALL show a block "Verklaringen" with the statement of truth and the privacy consent the binding enables, unchecked. The server MUST refuse a submission without each required statement, and SHALL record per statement the key, the text version and the time it was accepted.

#### Scenario: Forgetting the privacy consent
- **WHEN** the resident presses "Versturen" with only the statement of truth ticked
- **THEN** the error summary names the privacy statement and nothing is sent

#### Scenario: The record
- **WHEN** a submission with both statements is accepted
- **THEN** it holds both statements with their text version and time

### Requirement: The confirmation page and mail follow the form's settings (REQ-FCI-003)

After a submission the confirmation page SHALL show the binding's title, its body with the reference and the decision date filled in, the steps that follow, a PDF of the request and, for a signed-in resident, "Naar mijn zaken". When `confirmationMail.enabled`, the portal SHALL send one mail to the form's e-mail answer with the reference, a summary of the visible answers and the PDF, and the page SHALL say where it went. The summary MUST NOT contain file contents, signatures or a BSN. A refused mail SHALL mark the submission "Bevestiging mislukt".

#### Scenario: Mail with a summary
- **WHEN** Sanne sends a Woo request with sanne.devries@example.nl
- **THEN** she gets one mail with WOO-2026-4F7Q2D, her answers and the PDF, and the page says "Wij hebben een bevestiging gestuurd naar sanne.devries@example.nl"

#### Scenario: A full mailbox
- **WHEN** the mail server refuses the confirmation
- **THEN** PtInzendingen lists the submission under "Bevestiging mislukt"

#### Scenario: No decision date
- **WHEN** the case type gives no decision date
- **THEN** the sentence about the decision date is left out
