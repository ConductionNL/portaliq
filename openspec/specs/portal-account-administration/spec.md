# portal-account-administration Specification

## Purpose

Staff issue, invite, withdraw and approve portal accounts from the admin app,
through the validated actions rather than the raw object form. Requested by the
portaliq parity matrix rows `id-staff-invite`, `id-staff-provision-desk`,
`id-staff-void` and `id-registration-policy`.

## Requirements

### Requirement: Staff invite an address and portaliq mails it (REQ-ISA-001)

A staff member holding `portal.provision` SHALL be able to invite an e-mail
address from the admin app. Portaliq SHALL mail the invitation link to that
address and SHALL NOT show the secret to the staff member.

#### Scenario: A clerk invites a supplier
- **GIVEN** a clerk holding `portal.provision`
- **WHEN** they invite piet@leverancier.nl from "Invite someone"
- **THEN** a mail with the invitation link goes to piet@leverancier.nl, and the clerk sees the invitation as sent with its expiry date, not the link

### Requirement: Staff see and withdraw invitations (REQ-ISA-002)

The admin app SHALL list the invitations of the organisation with their
address, state, sent date, expiry and sender. A staff member holding
`portal.provision` SHALL be able to withdraw an invitation that was not yet
accepted; a withdrawn invitation SHALL admit nobody.

#### Scenario: A withdrawn invitation stops working
- **GIVEN** an invitation that was sent and not accepted
- **WHEN** a clerk chooses "Withdraw invitation" and the invitee then follows the link
- **THEN** the list shows the invitation as withdrawn and the invitee reads "Deze uitnodiging is niet meer geldig." (board PtUitnodigingen)

### Requirement: Staff issue and withdraw accounts through the validated actions (REQ-ISA-003)

The admin app SHALL issue a portal account only through the provision action,
so its identity de-duplication applies. A pending account SHALL offer
"Withdraw this account" with a required reason; an active account SHALL NOT
offer it.

#### Scenario: A clerk issues an account at the desk
- **GIVEN** a clerk holding `portal.provision` and a resident without DigiD with a checked e-mail address
- **WHEN** the clerk fills in "Issue an account" with that address, marked verified
- **THEN** a pending account exists for that address, matched on their first sign-in with the e-mail provider

#### Scenario: A duplicate identity is refused
- **GIVEN** an account already exists for identity reference 999993653
- **WHEN** a clerk issues another account for 999993653
- **THEN** the dialog shows the refusal and no second account is made

### Requirement: Staff set the registration policy and approve registrations (REQ-ISA-004)

An administrator SHALL be able to set a portal's registration policy to off,
approval or activation and to list allowed e-mail domains. Under approval, the
admin app SHALL list the registrations waiting for a decision, and a staff
member holding `portal.provision` SHALL approve or refuse each one; a refusal
SHALL need a reason.

#### Scenario: A registration is approved
- **GIVEN** a portal with registration policy approval and one pending self-registration
- **WHEN** a clerk chooses "Approve" on it in "Waiting for approval"
- **THEN** the account becomes active and leaves the list

### Requirement: Staff revoke one portal session (REQ-ISA-010)

An instance administrator SHALL be able to revoke one portal session by its token id. The permission check SHALL run before the session is read or written. Only that session SHALL stop working; other sessions of the same account and organisation SHALL keep working. The revocation SHALL be recorded as `admin-revoke` naming the administrator. A token id no live session carries SHALL answer not found and record nothing. The Sessions list SHALL offer "Sessie intrekken" on a live session and "Naar het account" on every session that names its account.

#### Scenario: A lost phone
- **WHEN** an administrator chooses "Sessie intrekken" on Sanne's phone session and confirms
- **THEN** that bearer is refused from then on
- **AND** Sanne's laptop session still works
- **AND** the history shows `admin-revoke` with the administrator's name

#### Scenario: Not an administrator
- **WHEN** a user who is not an instance administrator posts to the revoke route
- **THEN** Nextcloud refuses the request before the session is read

#### Scenario: From the session to its account
- **WHEN** a clerk chooses "Naar het account" on a session row
- **THEN** the account the session was issued for opens

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
