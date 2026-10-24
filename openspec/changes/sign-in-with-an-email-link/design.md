# Design: sign-in-with-an-email-link

## Security note (to review before build)

A mailed link is a bearer credential: whoever reads the mailbox signs in. The design limits what that buys and what it leaks.

| Risk | Measure |
|---|---|
| Account enumeration through the form | the same answer and the same timing for a known and an unknown address |
| A link forwarded or read later | single use, 15 minutes, spent on first open; a second open answers "deze link is al gebruikt" |
| Token theft from storage | only a hash of the token is stored, with the account id and expiry |
| Mail flooding | rate limit per address (3 per hour) and per client address (20 per hour), `#[AnonRateLimit]` |
| A link opened by a mail scanner that follows links | the link opens a page with one button "Inloggen"; the button spends the token, not the GET |
| Session strength | assurance `low`; an action at `substantial` refuses it as it refuses any `low` session |
| Phishing look-alikes | the mail names the portal and the address it was asked for, and contains no other link |

## Why not the generic OIDC provider

The OIDC "e-mail" way needs an identity provider the school does not have. A participant of a training provider has only an address the employer gave. The deviation D-6 (invitation link, then a Nextcloud account) works but gives the participant a password to keep for two course days a year.

## Decision for Ruben

Turn this on for the academy example portal only after the security review below is accepted. Until then the example keeps D-6.

## Security review (8 Oct)

Reviewed against the table above, `portal-ways-in`, `portal-broker-envelope-login`, `portal-session-idle-and-sso`, `portal-identity-space` and the code on development: `PortalIdentityController` (the reference link, the closest precedent), `PortalReferenceLinkService`, `PortalIdentityMailer`, `PortalSessionService`, `SessionAdminController`, the `portalAccount` schema and the traffic client. The table above is sound in direction. It leaves out two ways an e-mail link reaches more than it should, and the precedent it would copy has three weaknesses that must not be copied.

Verdict: build after the mitigations below are in the spec and the tasks. None of them is a reason to drop the mode.

### High

**H1. The link can reach an account that holds a DigiD, eHerkenning or eIDAS identity.** "An existing portal account of that portal" includes accounts created by a broker login, with an `identityRef` and claims from DigiD. A `low` session on such an account reads everything that carries no `minTrust`, because a missing `minTrust` means `low` (`PortalSessionService::trustSatisfies`), and the account's own claims ride with the session subject. The mailbox would then open data that only DigiD should open.
Mitigation: the mode signs in only an account whose identity type is the new value `email`, that holds no `identityRef` from another provider and no claims a broker set, that is `active`, and whose organisation is the portal's. Any other account gets the same answer as an unknown address and no mail. The `portalAccount` schema's `identityType` enum gains `email`; without that the store refuses the account and the service still passes its unit tests. A later broker login of the same person creates or joins a broker account under the existing join rules (trust `substantial`), never upgrades the `email` account in place.

**H2. One stolen link becomes a permanent account.** Self-service contact addresses carry no trust check. In an `email-link` session an attacker can add their own address, confirm it from their own mailbox, make it preferred, and from then on request links to their own mailbox. The 15 minutes and single use stop mattering.
Mitigation: the address a link is sent to is the account's sign-in address, a field of its own that self-service in an `email-link` session cannot change. Changing it needs staff, or a session at `substantial` or higher. Every change mails the old address. Contact addresses added in an `email-link` session are never used to sign in.

### Medium

**M1. Timing reveals who has an account.** The precedent sends the mail inside the request for a known address only (`requestReferenceLink`), so a known address answers measurably slower. "The same timing" in the table has no mechanism behind it.
Mitigation: the request endpoint does the same work for every accepted request: it queues one background job and answers. The job looks the address up, mails or does nothing, and keeps the address in its argument only until it has run.

**M2. Single use is read then write, so two opens at once both sign in.** `PortalReferenceLinkService::redeem` reads the live row and then marks it used. Two requests in the same moment both pass the read.
Mitigation: the spend is one conditional step (lock, read again, spend), as the invitation redeem already does (`portal-identity-space`). Two simultaneous opens give one session; the other gets "deze link is al gebruikt".

**M3. A scanner that runs the page and presses the button spends the link and holds a session.** The button defeats scanners that only follow a GET. Safe-link sandboxes that render the page and press buttons get a live bearer, and the person gets "already used".
Mitigation: the request sets a short-lived, `HttpOnly`, `SameSite=Strict` cookie whose hash is stored with the token. Opened in that browser, the page shows one button. Opened in another browser, the page asks the person to type the address the link was sent to before the button works; a wrong address spends nothing and counts towards the brute-force limit. The button's POST carries a value the page itself fetched, so a plain replay of the request does nothing. A spent link answers with an offer to request a new one.

**M4. The token can land in the traffic store.** The secret rides in the fragment, which keeps it out of access logs and referrers. But the traffic client sends `pageLocation` as the full `location.href`, fragment included, and `TrafficEventValidator` stores it as sent. Whether the page strips the fragment before the first page view is a matter of load order, not a rule.
Mitigation: the page removes the fragment with `history.replaceState` before any other script reads the location, the traffic ingest drops the fragment of every `pageLocation`, and session recording never captures the link page. A test proves the stored event has no fragment. The same exposure probably applies to `#reference=`, `#invitation=` and `#activate=` today: inherited, reported here, not fixed by this change.

**M5. "3 per address per hour" cannot be built with `#[AnonRateLimit]`.** That attribute counts per client address, and alone it caps signed-in callers at Nextcloud's lower default. Nothing in the precedent counts per mailbox.
Mitigation: a counter keyed on the hash of the normalised address (lower case, trimmed), counted for known and unknown addresses alike. Over the limit the answer is the same sentence and no mail is sent. Per client: `#[AnonRateLimit]` and `#[UserRateLimit]` together. A cap per portal per hour on outgoing link mails protects the sender's reputation. The redeem endpoint carries its own rate limit and `#[BruteForceProtection]`.

**M6. The new mode would open "Create an account".** REQ-IWI-002 shows registration when the portal "has an e-mail based sign-in". If `email-link` counts as one, a portal that declares it plus a registration policy lets anyone create an account with only a mailbox, which the proposal rules out.
Mitigation: `email-link` does not count as the e-mail based sign-in that REQ-IWI-002 and REQ-IWI-005 look for.

### Low

**L1. Token entropy is not stated.** Mitigation: at least 48 characters from `ISecureRandom` over lower case and digits (about 248 bits), stored only as its SHA-256 with the account id, the browser cookie hash and the expiry, compared with `hash_equals`. A fast hash is enough at that entropy.

**L2. Signing in to the wrong account (login CSRF).** Someone can send a victim a link to the attacker's own account; the victim then types their details into it. Mitigation: the button page names the portal and the masked address of the account it signs in to. The redeem never reuses or upgrades a bearer sent with it: it mints a fresh session with a fresh `jti`, replaces any session the page held, and the session records its method `email-link` and trust `low`. A refresh never raises the trust.

**L3. Logging.** Mitigation: no log line, audit entry, error or HTTP answer carries the token, the link or the full address (REQ-IWI-001 holds). Requests, mails, sign-ins and refusals are logged by account id and address hash; a sign-in is an audit trail entry with the account, the method and the moment.

**L4. Revocation is per organisation only.** `SessionAdminController` revokes every session of an organisation, nothing smaller. Mitigation: a new link voids every unspent earlier link of that account; a withdrawn or disabled account voids its links (checked at redeem, not only at request); staff can revoke one account's links and sessions; after each `email-link` sign-in the account's address gets a short "u bent ingelogd" mail so a misuse is seen.

**L5. Idle and sign-out rules.** Mitigation: an `email-link` session is an ordinary bearer under REQ-SIS-001 to REQ-SIS-004 (idle window, warning, absolute cap). It never takes part in silent sign-in (REQ-SIS-005) and gets no `logoutUrl` (REQ-SIS-006).

**L6. One address on more than one account.** Mitigation: when the address is the sign-in address of more than one eligible account of the portal's organisation, no link is sent, the answer is the same, and the log records the count only.

**L7. Mail interception remains.** Whoever reads the mailbox within 15 minutes signs in; this is accepted only because the session is `low` and H1 and H2 keep it away from anything more. The mail names the portal and the address, carries one link to the portal's own address, and says to ignore it when not asked for. The lifetime stays 15 minutes; it is not configurable upward.

### Binding to the browser, decided

Strict binding (the link works only in the browser that asked) would break the main case: asking on a laptop, opening on a phone. M3's cookie gives the requesting browser the one-button path and asks another browser for the address. That stops scanners and casual forwarding without blocking the phone.
