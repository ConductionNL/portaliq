# portal-ways-in Specification

## Purpose
A person with no portal account can create one, accept an invitation, or follow
one case with its case number. Requested by the portaliq parity matrix rows
`id-self-registration-form`, `id-reference-link`, `id-invitation-accept` and
`sib-dossiq-q1-14`.

## Requirements

### Requirement: Every way in sends its secret by mail (REQ-IWI-001)

The portal SHALL send the one-time secret of a reference link, an invitation
and a registration activation to the address it belongs to, inside a link, and
SHALL NOT return that secret in any HTTP answer or write it to a log.

#### Scenario: A reference link arrives by mail
- **GIVEN** a resident who asks for a reference link for case Z-2026-0042 with their e-mail address
- **WHEN** the request is accepted
- **THEN** the answer says a mail was sent, and the link is only in the mail

### Requirement: You can create an account where the portal allows it (REQ-IWI-002)

The sign-in screen SHALL offer "Create an account" only when the portal's
registration policy is not off and the portal has an e-mail based sign-in. The
form SHALL pass the portal's challenge. Under the activation policy the account
SHALL become active when the mailed link is followed; under the approval policy
it SHALL wait for staff.

#### Scenario: Registration under activation
- **GIVEN** a portal with registration policy activation and an e-mail based sign-in
- **WHEN** a visitor fills in "Create an account" and follows the activation link from the mail
- **THEN** their account is active and the screen reads "Your account is ready. Sign in with {provider}."

#### Scenario: Registration is off
- **GIVEN** a portal with registration policy off
- **WHEN** a visitor opens the sign-in screen
- **THEN** there is no "Create an account" door

### Requirement: A case number and an e-mail address open one case (REQ-IWI-003)

The sign-in screen SHALL offer "Follow a case with its case number" when a case
type of the portal admits the reference kind. Following the mailed link SHALL
open that one case read only, for a short session that is never refreshed. The
session SHALL NOT read any other case and SHALL NOT write anything.

#### Scenario: A resident follows their case without an account
- **GIVEN** a case type admitting the reference kind and a case Z-2026-0042 for anna@example.nl
- **WHEN** Anna asks for a link on the sign-in screen and follows it from her mail
- **THEN** the portal shows the status and history of Z-2026-0042, with no way to change it

#### Scenario: The reference session cannot reach another case
- **GIVEN** a reference session for case Z-2026-0042
- **WHEN** the browser requests case Z-2026-0043 by id
- **THEN** the answer is not found

#### Scenario: A link works once
- **GIVEN** a reference link that was already followed
- **WHEN** it is followed again
- **THEN** the screen reads "This link is no longer valid."

### Requirement: You can accept an invitation (REQ-IWI-004)

Following an invitation link SHALL show the portal's name and an accept button.
Accepting SHALL create the account for the invited address and SHALL tell the
person how to sign in next. An expired, used or revoked invitation SHALL say it
is no longer valid.

#### Scenario: An invited supplier accepts
- **GIVEN** an invitation mailed to piet@leverancier.nl
- **WHEN** Piet follows the link and chooses "Accept"
- **THEN** his account exists and the screen reads "Your account is ready. Sign in with {provider}."

### Requirement: The sign-in screen shows only the doors that lead somewhere (REQ-IWI-005)

The sign-in screen SHALL show a way in only when the portal's configuration
allows it and the path after it works: registration needs an e-mail based
sign-in, the reference door needs a case type admitting the reference kind.

#### Scenario: No e-mail based sign-in, no registration
- **GIVEN** a portal whose only sign-in is DigiD and whose registration policy is approval
- **WHEN** a visitor opens the sign-in screen
- **THEN** there is no "Create an account" door
