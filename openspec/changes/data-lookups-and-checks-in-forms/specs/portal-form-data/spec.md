## ADDED Requirements

### Requirement: A form fills in street and town from postcode and house number (REQ-DIF-001)

A field of type `addressNL` on a binding with `addressLookup: true` SHALL look up street and town in OpenRegister's BAG register once postcode and house number are filled, fill them in, and leave them editable. When no address is found it SHALL say so and let the resident type street and town.

#### Scenario: Sanne's address
- **WHEN** Sanne enters 3311 AB and 12
- **THEN** Straat shows Lindelaan and Plaats shows Zuiddrecht, with "Wij vonden dit adres bij uw postcode en huisnummer."

#### Scenario: No match
- **WHEN** the postcode and number match no BAG address
- **THEN** the form says no address was found and Straat and Plaats stay empty and editable

### Requirement: Dutch formats are checked while typing and on the server (REQ-DIF-002)

A field with `format` set to `bsn`, `iban`, `nl-licence-plate`, `phone-nl`, `phone-international`, `postcode`, `kvk` or `kvk-branch` SHALL be checked when the resident leaves it and again by the server on submit. The server MUST refuse a value that fails its format. A licence plate SHALL be accepted with or without dashes and stored in capitals without dashes.

#### Scenario: A wrong IBAN
- **WHEN** the resident enters NL91 ABNA 0417 1643 01
- **THEN** the field shows "Dit IBAN klopt niet. Controleer de cijfers."

#### Scenario: A BSN that fails the elfproef
- **WHEN** a submission sends BSN 123456780
- **THEN** the server refuses it and names the field

#### Scenario: A plate with dashes
- **WHEN** the resident enters gz-482-k
- **THEN** the submission stores GZ482K

### Requirement: Choices can come from a central reference list (REQ-DIF-003)

A field whose `options.referenceList` names a list SHALL offer the active items of that list as held in OpenRegister when the form renders. The server MUST refuse a value that is not an active item of the list.

#### Scenario: A new housing type
- **WHEN** the municipality adds "Tiny house" to the list woningtypen
- **THEN** every form that uses the list offers "Tiny house" within an hour, without a form change

### Requirement: A resident can choose family members from the BRP (REQ-DIF-004)

A field of type `familyMembers` SHALL, for a resident signed in with DigiD, list the partner and children the BRP registers (on the same address when `sameAddressOnly`), showing name, relation and birth year only, as checkbox cards. The server MUST check every chosen person against the BRP on submit. Without a DigiD session the field SHALL NOT render its list.

#### Scenario: Moving with the family
- **WHEN** Sanne opens "Wie verhuist er mee" and chooses Henk, Noor and Daan
- **THEN** the submission names the three as moving with her

#### Scenario: A forged person
- **WHEN** a submission names a person reference the BRP does not link to the resident
- **THEN** the server refuses it

### Requirement: A step can fetch data from a connection (REQ-DIF-005)

A step that declares `fetch` SHALL call the named integriq source from the server at the step change, with the declared answers, and show the outputs as read-only fields or a check line. When the call fails the step SHALL show "Er is een storing" with "Opnieuw proberen", and the draft MUST stay.

#### Scenario: Ownership from the Kadaster
- **WHEN** the address step is done and the Kadaster source answers that the building is Sanne's
- **THEN** the next step shows "Volgens het Kadaster staat dit pand op uw naam."

#### Scenario: The connection is down
- **WHEN** the source does not answer
- **THEN** the step shows "Er is een storing" and "Uw concept blijft bewaard"

### Requirement: A form can offer the variants of a product with their price (REQ-DIF-006)

A field of type `productVariant` SHALL offer the variants of its product from the portal's product catalogue with name and price. When the form is paid on submit, the amount SHALL be the chosen variant's catalogue price, read on the server. The server MUST ignore any amount the browser sends.

#### Scenario: A visitor permit
- **WHEN** the resident picks "Bezoekersvergunning, € 12,00 per jaar"
- **THEN** the pay step asks for € 12,00
