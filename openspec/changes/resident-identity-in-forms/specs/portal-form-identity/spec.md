## ADDED Requirements

### Requirement: A resident can sign a form (REQ-RIF-001)

A field of type `signature` SHALL offer a drawing box with "Opnieuw tekenen" and a single-pointer alternative to type the name instead. The signature SHALL be stored as an image on the submission. The server MUST refuse an empty signature for a required field.

#### Scenario: Signing with a finger
- **WHEN** the organiser draws a signature on a phone and sends the street party form
- **THEN** the submission holds the signature image and the case app receives it

#### Scenario: Typing instead of drawing
- **WHEN** a keyboard user chooses "Typ uw naam in plaats van te tekenen" and types "Sanne de Vries"
- **THEN** the field counts as signed and the image shows the typed name

### Requirement: A form can require a verified e-mail address (REQ-RIF-002)

An `email` field with `verify: true` SHALL send a six-digit code to the address, valid for 15 minutes and for at most 5 tries, with a new code available after 60 seconds. The server MUST refuse a submission whose verified field holds an address that was not verified in the same draft or session.

#### Scenario: The right code
- **WHEN** Sanne enters the code from the mail within 15 minutes
- **THEN** the field shows the address as checked and the form can be sent

#### Scenario: An unverified address
- **WHEN** a submission arrives with a verified field whose address never received a matching code
- **THEN** the server refuses it and names the field

### Requirement: A form can require a second person to co-sign (REQ-RIF-003)

A binding with `cosign.required` SHALL hold each submission in state `awaiting-cosign` and MUST NOT deliver it until it is co-signed. The co-signer SHALL get an invitation mail with a code and a deadline, sign in with their own DigiD, see the request read-only and choose "Mede-ondertekenen" or "Weigeren". The co-signer's DigiD subject MUST differ from the submitter's. The submitter's confirmation and case SHALL show "Wacht op mede-ondertekening door {naam}" until the answer.

#### Scenario: Henk co-signs
- **WHEN** Sanne submits a Woo request that needs co-signing and Henk signs in with his DigiD, ticks "Ik heb de aanvraag gelezen en onderteken mee." and chooses "Mede-ondertekenen"
- **THEN** the request is delivered with Henk's co-signature and Sanne's case no longer says it waits

#### Scenario: Co-signing your own request
- **WHEN** the submitter opens the co-sign link and signs in with the same DigiD
- **THEN** the portal refuses the co-signature

#### Scenario: The deadline passes
- **WHEN** nobody co-signs before 22 October 2026
- **THEN** the request is not delivered and the submitter is told by mail and on the case

### Requirement: A resident can sign in with Yivi and share only what the form asks (REQ-RIF-004)

An organisation SHALL be able to route a `yivi` sign-in through its broker. A form MAY name `yiviAttributes`; the form SHALL list those attributes before the Yivi app opens and prefill the named fields from the disclosed values. The session MUST hold only the disclosed attributes, and a form whose `minTrust` exceeds the level the broker states MUST NOT open.

#### Scenario: Name and e-mail from Yivi
- **WHEN** a form asks for full name and e-mail and the resident discloses them in Yivi
- **THEN** the name and e-mail fields are filled and nothing else about the resident is stored

#### Scenario: Not enough assurance
- **WHEN** a form needs level substantial and the broker states basic for the Yivi session
- **THEN** the form does not open and says which sign-in it needs

### Requirement: An employee can fill in a form for a resident or company (REQ-RIF-005)

A binding with `staffMayFill` SHALL show "Voor medewerkers" on its sign-in choice. A Nextcloud user in the portal's desk group SHALL be able to continue as Inwoner (by BSN, with BRP prefill), Bedrijf (by KvK number) or under their own name with the applicant noted. The submission and the delivery SHALL record the applicant and the employee as `filledBy`. Every BSN or KvK lookup MUST write an audit line naming the employee.

#### Scenario: At the desk
- **WHEN** an employee continues as Inwoner with Sanne's BSN and sends the Woo request
- **THEN** the case names Sanne as applicant and the employee as the one who filled it in, and the audit log has the lookup

#### Scenario: Not in the desk group
- **WHEN** a Nextcloud user outside the desk group tries "Inloggen met werkaccount"
- **THEN** the portal refuses staff mode
