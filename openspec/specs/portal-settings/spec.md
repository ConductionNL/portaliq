# portal-settings Specification

## Purpose
A beheerder runs a portal from its settings tab: the portal's details as one form, its domains with their verification state and the end of their certificate, and the list of people who look after the portal. Boards: PtPortalInstellingen and PtPortal.

## Requirements

### Requirement: A beheerder sees and adds a portal's domains with their state (REQ-PDE-001)

The portal settings tab SHALL list every domain of the portal with its state, "Geverifieerd" or "Wacht op verificatie". A pending domain SHALL show the TXT record name and value it must publish. A beheerder SHALL be able to add a domain and to run the check again, through the existing verification. A domain that is not verified MUST NOT serve the portal.

#### Scenario: A new domain waits for its record
- **WHEN** a beheerder adds `nieuw.zuiddrecht.nl`
- **THEN** the card lists it as "Wacht op verificatie" with the TXT name `_portaliq-verify.nieuw.zuiddrecht.nl` and its token

#### Scenario: The record is published
- **WHEN** the record exists and the beheerder chooses "Opnieuw controleren"
- **THEN** the domain reads "Geverifieerd"

#### Scenario: A hostname with a scheme is refused
- **WHEN** a beheerder enters `https://nieuw.zuiddrecht.nl/`
- **THEN** nothing is added and the card says the address is a plain host name

### Requirement: A verified domain shows when its certificate ends (REQ-PDE-002)

A domain SHALL carry `certificateValidUntil` and `certificateCheckedAt`. A verification run SHALL read the end date of the certificate the host presents on port 443 for a verified domain and store it. A host that does not answer MUST keep its earlier end date and update only `certificateCheckedAt`. The card SHALL show "geldig tot {datum}", or "nog niet gecontroleerd" when there is none.

#### Scenario: The end date is stored
- **WHEN** a verified domain presents a certificate valid until 14 January 2027
- **THEN** the domain's `certificateValidUntil` is that moment and the card reads "geldig tot 14 jan 2027"

#### Scenario: The host does not answer
- **WHEN** the host does not answer on port 443
- **THEN** `certificateValidUntil` keeps its earlier value and `certificateCheckedAt` is updated

### Requirement: A portal lists who looks after it (REQ-PDE-003)

A portal SHALL accept `editors`, a list of `{userId, displayName, role}` with `role` one of `chief-editor` and `editor`. The side column SHALL show them as "Beheerders" with initials, name and role ("Hoofdredacteur", "Redacteur"), and the settings tab SHALL let a beheerder add and remove them. The list MUST NOT grant any right to change pages.

#### Scenario: The list is shown
- **WHEN** the portal lists Pieter Jansen as chief-editor and Anouk Bakker as editor
- **THEN** the card "Beheerders" shows both with their role

#### Scenario: A listed user gains no right
- **WHEN** a user in `editors` is not an administrator and not in a group of "Paginaredacteuren"
- **THEN** the user still cannot change pages

### Requirement: The portal's details are one form (REQ-PDE-004)

The settings tab SHALL offer name, slogan, address in portaliq, organisation, kind, header, languages, the name of the login button, the logo and the websites that may embed the portal as one form. Saving MUST refuse an empty name, an address that is not lowercase letters, digits and dashes, no language, and an embed origin that is not `http(s)://host[:port]`.

#### Scenario: An empty name is refused
- **WHEN** the name is empty and the beheerder saves
- **THEN** nothing is saved and the field says to give the portal a name

#### Scenario: An embed origin is checked
- **WHEN** an origin reads `www.example.nl` without a scheme
- **THEN** nothing is saved and the field says an address looks like https://www.example.nl
