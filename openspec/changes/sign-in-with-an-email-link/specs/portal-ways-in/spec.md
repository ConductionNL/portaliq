## ADDED Requirements

### Requirement: A portal may let an existing e-mail account sign in with a one-time e-mail link (REQ-IWI-006)

A portal MAY declare the sign-in mode `email-link`. A portal that does not declare it MUST NOT offer or accept it. For the sign-in address of exactly one eligible account, portaliq MUST mail a link that is single use, expires after 15 minutes, and signs the person in at assurance `low` when its page's button is pressed, never on the GET. An eligible account MUST have identity type `email`, MUST be `active`, MUST belong to the portal's organisation, and MUST NOT hold an identity reference or claims set by DigiD, eHerkenning, eIDAS or another broker. Any other account, an unknown address and an address shared by more than one eligible account MUST get the same answer and no mail. A later broker login MUST NOT upgrade an `email` account in place.

#### Scenario: Tom asks for a link
- **GIVEN** Tom has an `email` portal account on the academy portal and the portal declares `email-link`
- **WHEN** he enters his address, opens the mailed link within 15 minutes in the same browser and presses "Inloggen"
- **THEN** he is signed in at assurance `low`, and the same link a second time answers that it was used
- @e2e exclude spec-only proposal; the token rules asserted in PHPUnit

#### Scenario: An account with a DigiD identity gets no link
- **GIVEN** an account created by a DigiD login whose contact address is anna@example.nl
- **WHEN** anna@example.nl is entered in the e-mail link form
- **THEN** the page answers the same sentence as for an unknown address and no mail is sent
- @e2e exclude asserted in PHPUnit on the account eligibility check

### Requirement: The e-mail link form reveals nothing about accounts (REQ-IWI-007)

The request endpoint MUST answer every accepted request with the same sentence ("Als dit adres bij ons bekend is, ontvangt u een link") and MUST do the same work for a known and an unknown address: it MUST queue one background job and answer, and the job MUST look the address up and mail or do nothing. The job MUST NOT keep the address after it has run.

#### Scenario: An unknown address
- **GIVEN** an address without an account
- **WHEN** it is entered
- **THEN** the page answers the same sentence as for a known address, one job is queued, and no mail is sent
- @e2e exclude asserted in PHPUnit on the request endpoint

### Requirement: The e-mail link form is rate limited per mailbox, per client and per portal (REQ-IWI-008)

Portaliq MUST count link requests per hash of the normalised address (lower case, trimmed), for known and unknown addresses alike, and MUST send no more than 3 links per address per hour; over the limit the answer MUST stay the same sentence and no mail is sent. The request endpoint MUST carry both `#[AnonRateLimit]` and `#[UserRateLimit]` at 20 per client per hour. Portaliq MUST cap outgoing link mails per portal per hour. The redeem endpoint MUST carry its own rate limit and `#[BruteForceProtection]`.

#### Scenario: A fourth request in an hour
- **GIVEN** three link requests for tom@example.nl in the last hour
- **WHEN** a fourth arrives from another client
- **THEN** the answer is the same sentence and no mail is sent
- @e2e exclude asserted in PHPUnit on the per-address counter

### Requirement: The e-mail link token is strong, stored as a hash and spent once (REQ-IWI-009)

The token MUST be at least 48 characters from `ISecureRandom` over lower case and digits, MUST be stored only as its SHA-256 with the account id, the requesting browser's cookie hash and the expiry, and MUST be compared with `hash_equals`. The spend MUST be one conditional step (lock, read again, spend), so two simultaneous opens give one session. A new link MUST void every unspent earlier link of the same account. A link of an account that is no longer `active` MUST NOT sign in, checked at redeem. The token MUST ride in the link's fragment, never in the path or query.

#### Scenario: Two opens at the same moment
- **GIVEN** one unspent link
- **WHEN** two redeems of it arrive at the same moment
- **THEN** one session is minted and the other answers that the link was used
- @e2e exclude asserted in PHPUnit on the token service

#### Scenario: A newer link voids the older one
- **GIVEN** Tom asked for a link and then asked again
- **WHEN** he opens the first link
- **THEN** it answers that the link is no longer valid
- @e2e exclude asserted in PHPUnit on the token service

### Requirement: A link opened in another browser asks for the address first (REQ-IWI-010)

The request MUST set a short-lived `HttpOnly`, `SameSite=Strict` cookie whose hash is stored with the token. Opened in that browser, the link page MUST show one button "Inloggen". Opened in a browser without that cookie, the page MUST ask the person to type the address the link was sent to before the button works; a wrong address MUST spend nothing and MUST count towards the brute-force limit. The button's POST MUST carry a value the page itself fetched. The page MUST name the portal and the masked address of the account it signs in to.

#### Scenario: A mail scanner opens the link
- **GIVEN** a link opened by a scanner in a sandbox browser without the request cookie
- **WHEN** the scanner presses the button without typing the address
- **THEN** the link is not spent and no session is minted
- @e2e exclude asserted in PHPUnit on the redeem endpoint

### Requirement: An e-mail link session is a fresh low session that cannot raise itself (REQ-IWI-011)

The redeem MUST mint a fresh session with a fresh `jti` through `PortalSessionService`, MUST NOT reuse or upgrade a bearer sent with it, and MUST record the method `email-link` and trust `low`; a refresh MUST NOT raise the trust. The session MUST follow the idle window, warning and absolute cap of REQ-SIS-001 to REQ-SIS-004, MUST NOT take part in silent sign-in and MUST get no `logoutUrl`. In such a session the account's sign-in address MUST NOT be changed, and a contact address added in it MUST NOT be used to sign in. Changing the sign-in address MUST need staff or a session at `substantial` or higher and MUST mail the old address.

#### Scenario: A stolen link does not become a lasting account
- **GIVEN** an `email-link` session on Tom's account
- **WHEN** it adds and confirms another address and makes it preferred
- **THEN** the sign-in address is still Tom's and links still go only to Tom's address
- @e2e exclude asserted in PHPUnit on self-service in an email-link session

#### Scenario: A bearer sent with the redeem is not upgraded
- **GIVEN** a browser holding a session of another account
- **WHEN** it redeems an e-mail link
- **THEN** a new session with a new `jti` is minted for the link's account and the old bearer is not reused
- @e2e exclude asserted in PHPUnit on the redeem endpoint

### Requirement: The e-mail link never reaches a log, an answer or the traffic store (REQ-IWI-012)

No log line, audit entry, error, HTTP answer or traffic event MUST carry the token, the link or the full address. The link page MUST remove the fragment with `history.replaceState` before any other script reads the location, the traffic ingest MUST drop the fragment of every `pageLocation`, and session recording MUST NOT capture the link page. Requests, mails, sign-ins and refusals MUST be logged by account id and address hash, and every sign-in MUST be an audit trail entry with the account, the method and the moment. After each sign-in the account's address MUST get a short notice that it was used.

#### Scenario: The traffic event keeps no fragment
- **GIVEN** a portal that measures traffic
- **WHEN** a visitor opens an e-mail link
- **THEN** the stored page view carries the page path without the fragment
- @e2e exclude asserted in PHPUnit on the traffic ingest and a node test on the link page

### Requirement: Staff can revoke an account's e-mail links and sessions (REQ-IWI-013)

Staff MUST be able to revoke every unspent e-mail link and every live session of one portal account, without revoking the rest of the organisation.

#### Scenario: Staff revoke a participant's access
- **GIVEN** a participant with an unspent link and a live session
- **WHEN** staff revoke the account's links and sessions
- **THEN** the link answers that it is no longer valid and the bearer answers 401
- @e2e exclude asserted in PHPUnit on the revoke endpoint

### Requirement: An e-mail link does not open registration (REQ-IWI-014)

The mode `email-link` MUST NOT count as the e-mail based sign-in that REQ-IWI-002 and REQ-IWI-005 look for, so declaring it MUST NOT show "Create an account".

#### Scenario: A portal with only the e-mail link and registration on
- **GIVEN** a portal declaring `email-link`, registration policy activation and no other e-mail based sign-in
- **WHEN** a visitor opens the sign-in screen
- **THEN** there is no "Create an account" door
- @e2e exclude asserted in PHPUnit on `PortalWaysInResolver`
