# citizen-case-withdraw-screen Specification

## Purpose
A resident withdraws their own request from the case screen of the portal: a button when the case type allows it, a confirmation with an optional reason, and a withdrawn request that stays readable. The backend shipped with `withdrawing-your-own-case-from-the-portal`; this capability is the screen that reaches it. Closes portaliq matrix rows `act-withdraw-case` and `sib-dossiq-2-47`.

## Requirements

### Requirement: The case screen offers withdrawal exactly as the server declares it (REQ-WDS-001)

The citizen case screen SHALL read the `withdrawal` state the server returns with the case. It SHALL show no withdrawal control when the case type declares none. It SHALL show "Withdraw this request" when the withdrawal is declared and open. It SHALL show the server's reason, and no button, when the withdrawal is declared and closed. This realises REQ-WOC-001 on the screen.

#### Scenario: A resident sees the button
- **GIVEN** a running request whose case type declares withdrawal open in its current status
- **WHEN** the resident opens it on the portal case screen
- **THEN** a "Withdraw this request" button is shown

#### Scenario: A type without withdrawal shows nothing
- **GIVEN** a request whose case type declares no withdrawal
- **WHEN** the resident opens it
- **THEN** no withdrawal button and no withdrawal sentence are shown

#### Scenario: A decided request says why
- **GIVEN** a request past its withdrawal window
- **WHEN** the resident opens it
- **THEN** the case app's closed reason is shown and no button

### Requirement: Withdrawing takes a confirmation, with an optional reason (REQ-WDS-002)

Pressing "Withdraw this request" SHALL open a confirmation step that states what withdrawing means, in the case app's words when it supplies them, and offers an optional reason. The withdrawal SHALL be sent only when the resident confirms, with only the reason in the request. Cancelling SHALL send nothing. A refusal SHALL be shown as the server's sentence. This realises REQ-WOC-003 on the screen.

#### Scenario: Cancelling changes nothing
- **GIVEN** a resident on the confirmation step
- **WHEN** they press "Keep my request"
- **THEN** no request is sent and the request is still running after a reload

#### Scenario: A resident withdraws with a reason
- **GIVEN** a resident on the confirmation step
- **WHEN** they type "Ik ben toch niet verhuisd." and press "Withdraw request"
- **THEN** the screen says "Your request has been withdrawn." and the case carries that reason

#### Scenario: The server refuses
- **GIVEN** a window that closed while the resident had the screen open
- **WHEN** they confirm the withdrawal
- **THEN** the screen shows the server's sentence and the request is unchanged
- @e2e exclude a window closing mid-screen cannot be staged reliably in a browser; the refusal shape is pinned by tests/case-withdraw-screen.spec.mjs and the controller's 409 by the API spec withdrawing-your-own-case-from-the-portal.spec.ts

### Requirement: A withdrawn request stays readable and cannot be undone from the portal (REQ-WDS-003)

After a withdrawal the case screen SHALL show the answers read-only, "Withdrawn on {date}.", and the reason when one was given. It SHALL NOT list the withdrawal fields as ordinary answers, and SHALL offer no control to undo, reopen or delete. This realises REQ-WOC-005 on the screen.

#### Scenario: The withdrawn request after a reload
- **GIVEN** a request the resident withdrew with a reason
- **WHEN** they reload the case screen
- **THEN** it shows the answers, "Withdrawn on" with the date, and their reason, and no withdraw or undo control
