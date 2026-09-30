---
status: proposed
---

# Spec: portal-account-administration

**Status:** proposed
**Scope:** portaliq (owner)
**Depends on:** `portal-identity-space`, `portal-identity-and-the-organisations-cases`, `identity-profile-page` and `identity-ways-in-screens` (the identity mailer and its invitation template)

## Purpose

Staff issue, invite, withdraw and approve portal accounts from the admin app,
through the validated actions rather than the raw object form. Requested by the
portaliq parity matrix rows `id-staff-invite`, `id-staff-provision-desk`,
`id-staff-void` and `id-registration-policy`.

## ADDED Requirements

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
- **THEN** the list shows the invitation as withdrawn and the invitee reads "This invitation is no longer valid."

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
