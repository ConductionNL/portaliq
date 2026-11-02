## ADDED Requirements

### Requirement: Staff send an invitation again (REQ-ISA-011)

A clerk holding `portal.provision` SHALL be able to send an invitation that is sent, opened or expired again. The permission check SHALL run before the invitation is read. Sending again SHALL replace the secret, so the earlier link admits nobody, and SHALL start a new validity window. The new link SHALL be mailed to the invited address only and SHALL never be shown to the clerk. An accepted or withdrawn invitation SHALL NOT be sent again, and the clerk SHALL read why. A mail that did not leave SHALL be reported as such.

#### Scenario: The mail went to spam
- **WHEN** a clerk chooses "Opnieuw versturen" on an open invitation to ans@example.org
- **THEN** ans@example.org receives a new link valid for a week
- **AND** the link from the first mail admits nobody
- **AND** the clerk reads "Uitnodiging opnieuw verstuurd naar ans@example.org" with the new date

#### Scenario: Already accepted
- **WHEN** a clerk sends an accepted invitation again
- **THEN** nothing is mailed and the clerk reads that it was already accepted

#### Scenario: Without the right
- **WHEN** a user without `portal.provision` posts to the resend route
- **THEN** the request is refused before the invitation is read
