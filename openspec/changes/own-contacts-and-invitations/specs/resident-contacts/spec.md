## ADDED Requirements

### Requirement: A resident keeps a list of approved contacts (REQ-ROC-001)

The portal SHALL offer the page "Mijn contacten" to a signed-in resident, listing their `portalContact` rows with state `approved`, filterable by role (Alle, Begeleider, Contact, Organisatie) with a count per role. Each row SHALL offer "Bericht sturen" and "Verwijderen". A resident MUST only read and write their own rows. Removing a contact SHALL set both rows to `withdrawn` and SHALL keep earlier messages.

#### Scenario: The list with filters
- **WHEN** Sanne has two Begeleider contacts, one Contact and one Organisatie, and opens Mijn contacten
- **THEN** she sees four contacts and the chips "Alle 4", "Begeleider 2", "Contact 1", "Organisatie 1"

#### Scenario: Another resident's contacts stay hidden
- **WHEN** a resident requests the contact rows of another subjectRef
- **THEN** the portal returns none of them

### Requirement: Someone becomes a contact only after both sides approve (REQ-ROC-002)

A request from another account SHALL show under "Wacht op goedkeuring" with "Accepteren" and "Weigeren". Accepting SHALL set both rows to `approved`; declining SHALL set both to `declined` and MUST NOT tell the requester who declined beyond "niet geaccepteerd". A contact SHALL see only the resident's name, the messages sent to them and the plans they are added to.

#### Scenario: Accepting a request
- **WHEN** Ahmed Bakker asks to add Sanne and she chooses "Accepteren"
- **THEN** both see each other under Uw contacten

#### Scenario: A contact sees no cases
- **WHEN** an approved contact opens the portal
- **THEN** none of Sanne's cases, tasks or personal details are readable for them

### Requirement: A resident can invite someone by e-mail (REQ-ROC-003)

The dialog "Iemand uitnodigen" SHALL take an e-mail address and an optional message. For an address without an account the portal SHALL mail a personal link valid for 14 days that opens registration with the address filled in; on activation both sides SHALL be contacts without a further approval. For an address with an account the portal SHALL send an approval request instead of a link. A pending invitation SHALL offer "Opnieuw versturen" and "Intrekken". The portal SHALL send at most 10 invitations per resident per day and at most one open invitation per address.

#### Scenario: Inviting a family member
- **WHEN** Sanne invites j.devries@example.nl with a short message
- **THEN** that address receives a mail with her message and a link valid for 14 days
- **AND** the invitation shows under "Wacht op goedkeuring" with "Wacht op reactie"

#### Scenario: The address already has an account
- **WHEN** Sanne invites an address that belongs to an existing account
- **THEN** no link is mailed and that account gets an approval request

#### Scenario: The daily limit
- **WHEN** a resident sends an eleventh invitation on one day
- **THEN** the portal refuses it with "U kunt vandaag geen uitnodigingen meer versturen."
