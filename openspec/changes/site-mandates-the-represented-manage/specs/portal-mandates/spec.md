## ADDED Requirements

### Requirement: A mandate MUST name its party in a typed form (REQ-SMR-001)

Every mandate portaliq writes MUST store `onBehalfOf` as `kvk:` followed by 8 digits, or `subject:` followed by a subject reference. When reading, an untyped value of 8 digits MUST count as `kvk:<value>` and any other untyped value as `subject:<value>`.

#### Scenario: An old access-request mandate still opens the cases
- GIVEN a mandate written before this change with `onBehalfOf: "87654321"`
- WHEN its holder opens "Mijn zaken"
- THEN the mandate counts as `kvk:87654321`

#### Scenario: A grant writes the typed form
- GIVEN staff grant an access request for company 87654321
- WHEN the mandate is written
- THEN its `onBehalfOf` is `kvk:87654321`

### Requirement: The represented party MUST see who may act for it (REQ-SMR-002)

A business session MUST see every mandate and open invitation whose `onBehalfOf` is its own KVK number, and a person every one whose `onBehalfOf` is their own subject reference. Each entry MUST show who, the label, the end date or "zonder einddatum", and the state. The party MUST come from the session. A session acting under a mandate MUST NOT see or manage the represented party's list.

#### Scenario: Jan-Willem's company list
- GIVEN Slagerij Van der Berg (KVK 12345678) gave mandates to Petra van der Berg and Administratiekantoor Kramer, and invited Tom Visser
- WHEN Jan-Willem, signed in with eHerkenning for that company, opens "Machtigingen"
- THEN he sees Petra and Kramer as "Actief" with their end dates, and Tom Visser as "Wacht op antwoord"

#### Scenario: Another company's mandate is not found
- GIVEN a mandate on behalf of KVK 87654321
- WHEN the session of KVK 12345678 asks to revoke it
- THEN the answer is 404 and the mandate stays active

### Requirement: A party MUST invite, never look up, the person it authorises (REQ-SMR-003)

Portaliq holds no BSN, so a person who never signed in cannot be named. "Iemand machtigen" MUST ask for an email address, a scope (all cases or chosen case types), a label and an optional end date in the future, and MUST send an invitation. It MUST NOT ask for a BSN or look a person up by name. The mandate MUST be written only when the invitee signs in and accepts, for the account that accepted, with the invitation's terms. The inviter MUST NOT be able to accept their own invitation.

#### Scenario: Tom accepts
- GIVEN an invitation from KVK 12345678 to tom@example.nl for "Mag alleen bezwaren indienen en volgen", valid until 30 June 2027
- WHEN Tom signs in with DigiD and accepts it
- THEN a mandate exists with holder `subject:<Tom's subjectRef>` on behalf of `kvk:12345678` with that label, scope and end date
- AND the company list shows Tom as "Actief"

#### Scenario: A private person authorises their daughter
- GIVEN H. Bakker signs in with DigiD and invites linda@example.nl to act for him on all cases
- WHEN Linda Bakker signs in and accepts
- THEN a mandate exists with holder `subject:<Linda's subjectRef>` on behalf of `subject:<H. Bakker's subjectRef>`
- AND H. Bakker's list shows Linda as "Actief"

#### Scenario: An end date in the past is refused
- GIVEN the invitation form with end date 1 January 2026
- WHEN the party sends it on 2 October 2026
- THEN no invitation is sent and the form names the end date

### Requirement: Either side MUST be able to end a mandate (REQ-SMR-004)

The represented party MUST be able to revoke a mandate, revoke an open invitation and set or move the end date to a future date. The holder MUST be able to stop their own mandate. Each end MUST record who and when. A revoked or expired mandate MUST grant nothing from the next request on, and an acting-for choice under it MUST fall back to acting for oneself.

#### Scenario: Linda stops acting for her father
- GIVEN Linda Bakker holds a mandate on behalf of H. Bakker and is acting under it
- WHEN she chooses "Machtiging stoppen" and confirms
- THEN the mandate is revoked with Linda as `revokedBy`
- AND her next page shows her own cases and no acting-for bar

#### Scenario: The company revokes Kramer
- GIVEN Administratiekantoor Kramer holds an active mandate from KVK 12345678
- WHEN Jan-Willem chooses "Intrekken" for it
- THEN Kramer's next request reads no case of KVK 12345678

### Requirement: A company MUST hold the mandate it accepts (REQ-SMR-005)

When an eHerkenning session for a company accepts an invitation, the mandate's holder MUST be `kvk:<that company's 8-digit number>`. Every eHerkenning sign-in for that KVK number MUST then carry the mandate. A DigiD session MUST hold the mandate it accepts as `subject:<its subjectRef>`. A mandate without `holder` MUST count as held by `subject:<its subjectRef>`. The case timeline MUST still name the person who acted, as "{name}, namens {party}".

#### Scenario: Kramer's colleague acts under the company's mandate
- GIVEN Administratiekantoor Kramer (KVK 87654321) accepted a mandate from KVK 12345678 through an eHerkenning sign-in by Anna
- WHEN her colleague Bas signs in with eHerkenning for KVK 87654321
- THEN Bas can act for KVK 12345678 within the mandate's scope

#### Scenario: The timeline names Bas, not the company
- GIVEN Bas adds a document to a case of KVK 12345678 under that mandate
- WHEN the represented company opens the case timeline
- THEN the entry reads "Bas …, namens Slagerij Van der Berg"

#### Scenario: An old mandate keeps its holder
- GIVEN a mandate written before this change, with `subjectRef` and no `holder`
- WHEN that account signs in
- THEN the mandate counts as held by `subject:<that subjectRef>`

### Requirement: A mandate MUST move only forward through its states (REQ-SMR-006)

An invitation MUST be pending until it is accepted, revoked or expired. Accepting a pending invitation MUST create an active mandate. An active mandate MUST become revoked when either side ends it, and expired once its end date has passed. Revoked and expired MUST be final: no route MAY make either active again, and moving the end date MUST be refused once a mandate is revoked or expired. A new mandate needs a new invitation.

#### Scenario: Pending to active
- GIVEN a pending invitation
- WHEN the invitee signs in and accepts it
- THEN the invitation reads accepted and an active mandate exists

#### Scenario: Pending to revoked
- GIVEN a pending invitation
- WHEN the represented party revokes it
- THEN accepting the token afterwards is refused and no mandate is created

#### Scenario: Active to revoked
- GIVEN an active mandate
- WHEN its holder stops it
- THEN it reads revoked and grants nothing on the next request

#### Scenario: Active to expired
- GIVEN an active mandate with end date 1 October 2026
- WHEN its holder signs in on 2 October 2026
- THEN it grants nothing and reads expired in both lists

#### Scenario: Expired stays expired
- GIVEN an expired mandate
- WHEN the represented party sets a new end date in the future
- THEN the change is refused and the mandate stays expired
