---
status: implemented
---

# Spec: portal-profile

**Status:** implemented
**Scope:** portaliq (owner); a case app listens for the contact-details event
**Depends on:** `portal-identity-and-the-organisations-cases` (the self-service backend)

## Purpose

A signed-in resident or business user manages their own portal account: name,
e-mail addresses, phone numbers, how the organisation contacts them, and
removing the account. Requested by the portaliq parity matrix rows
`id-update-own-details`, `dem-cl-multiple-contact-addresses`,
`cmp-id-contact-channel`, `cmp-id-prompt-missing-email` and
`id-remove-own-account`.

## ADDED Requirements

### Requirement: You see your own account (REQ-IPP-001)

The portal SHALL offer a signed-in user a "My account" page showing their name,
their e-mail addresses and phone numbers with the preferred ones marked, and
their contact channel. The data SHALL come from
`GET /portal/api/identity/details`, which SHALL answer for the bearer's own
account only and SHALL NOT include the identity reference or any app claim.

#### Scenario: A resident opens their account
- **GIVEN** a resident signed in to the portal
- **WHEN** they open "My account"
- **THEN** they see their name, their confirmed and unconfirmed e-mail addresses, their phone numbers and their contact channel

#### Scenario: The endpoint never shows another account
- **GIVEN** no bearer on the request
- **WHEN** a client calls `GET /portal/api/identity/details`
- **THEN** the answer is 401 and no account is read

### Requirement: A new e-mail address is confirmed before it is used (REQ-IPP-002)

When a user adds or changes an e-mail address, the portal SHALL send a
confirmation mail with a one-time link to that address, and SHALL NOT use the
address for anything until the link is followed. The secret SHALL travel in
the link's fragment and SHALL NOT be logged.

#### Scenario: A changed address waits for the link
- **GIVEN** a resident whose confirmed address is old@example.nl
- **WHEN** they enter new@example.nl on "My account"
- **THEN** a confirmation mail goes to new@example.nl, and notifications still go to old@example.nl

#### Scenario: Following the link confirms the address
- **GIVEN** a confirmation mail sent to new@example.nl
- **WHEN** the resident follows the link
- **THEN** the portal shows new@example.nl as confirmed, and following the same link again reads "This link is no longer valid."
- @e2e exclude the CI instance captures no mail; pinned by PortalContactAddressServiceTest::testAChangedAddressWaitsForTheLinkAndNotificationsStayOnTheOldOne and tests/account-page.spec.mjs (the link is read once)

### Requirement: You keep several addresses, one of each kind preferred (REQ-IPP-003)

A user SHALL be able to keep several e-mail addresses and phone numbers and
mark one of each kind as preferred. Only a confirmed e-mail address SHALL be
markable as preferred. The preferred confirmed e-mail SHALL be the address
notifications go to.

#### Scenario: A second address becomes the preferred one
- **GIVEN** a resident with two confirmed addresses, a@example.nl preferred
- **WHEN** they mark b@example.nl as preferred
- **THEN** the next notification mail goes to b@example.nl
- @e2e exclude needs the confirmation mail first; pinned by PortalContactAddressServiceTest::testASecondConfirmedAddressMarkedPreferredIsWhereNotificationsGo

#### Scenario: An unconfirmed address cannot be preferred
- **GIVEN** a resident who just added c@example.nl and has not confirmed it
- **WHEN** they try to mark it preferred
- **THEN** the portal refuses and reads "Confirm this address first."

### Requirement: You choose how the organisation contacts you (REQ-IPP-004)

A user SHALL be able to choose one contact channel: portal only, e-mail, phone
or post. The portal SHALL record the choice on the account and SHALL announce
each change as `PortalContactDetailsChangedEvent`, carrying the subject, the
organisation and the channel.

#### Scenario: A resident chooses post
- **GIVEN** a resident whose contact channel is portal only
- **WHEN** they choose post on "My account"
- **THEN** the account records post and one `PortalContactDetailsChangedEvent` is dispatched naming post

### Requirement: You are asked for an e-mail address when there is none (REQ-IPP-005)

After sign-in, the portal SHALL show a notice asking for an e-mail address when
the account has no confirmed e-mail address or when notification dispatch has
flagged it as needing another contact. The notice SHALL link to "My account"
and SHALL be dismissible for the rest of the session.

#### Scenario: A first DigiD sign-in without an e-mail address
- **GIVEN** a resident signing in for the first time, with no e-mail address on the account
- **WHEN** the portal opens
- **THEN** they see "Add an e-mail address so we can tell you when something changes." with a link to "My account"

### Requirement: You can remove your own account and keep your cases (REQ-IPP-006)

The portal SHALL let a user remove their own portal account after a
confirmation step that says the cases stay with the organisation. Removal SHALL
sign the user out and SHALL NOT delete or change any case.

#### Scenario: A resident removes their account
- **GIVEN** a resident with one running case
- **WHEN** they choose "Remove my account" and confirm
- **THEN** they are signed out, their account reads removed, and the case is still there for the organisation
