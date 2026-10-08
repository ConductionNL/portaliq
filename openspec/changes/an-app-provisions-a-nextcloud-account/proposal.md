## Why

Training participants of the Warmtepompacademie get no portal account on install (school portal
proof, 06 Oct, item 15). They sign in with their Nextcloud account, through the portal's
`nextcloud` sign-in mode, which finds the portal account whose `subjectRef` IS the Nextcloud user
id and refuses everyone else (it never creates one, on purpose: otherwise every user on the
instance would be a citizen of every portal with the mode). The provision event
(`PortalAccountProvisionRequestedEvent`, REQ-PIS-001) only creates a pending account under a freshly
minted subjectRef, which this mode can never match. learniq has no supported way to give a
participant the account the mode needs.

## What Changes

- The provision event takes two optional arguments: `nextcloudUid` and `portal`. With a user id the
  listener hands the request to a new `NextcloudAccountProvisioner` instead of the pending path.
- `NextcloudAccountProvisioner::provision()` writes, once, an ACTIVE account: `subjectRef` = the
  user id, the asked `audience` and `organisation`, `provisionedBy` = the dispatching app, the
  e-mail address as unverified when given. It refuses (a word in the event's refusal slot):
  - `refused`: an argument is missing; `unknown_app`: no app id (existing rule);
  - `unknown_user`: no such Nextcloud user;
  - `unknown_portal`, `portal_mismatch`, `mode_not_offered`: the portal is not published, is not
    the organisation's, or does not offer the `nextcloud` mode;
  - `conflict`: an account under that subjectRef exists in another organisation or audience, or a
    live account in the organisation already holds the e-mail address under its own subjectRef
    (an invitation's waiting account): never taken over, never a second account beside it;
  - `not_active`: the account under that subjectRef exists but is not active (a clerk closed it);
  - `unavailable`: the register could not be written.
  A repeat for the same user, organisation and audience answers the existing account and writes
  nothing (idempotent).
- Server side only: the event is dispatched in PHP by an app; there is no route. No claim is
  written; the app sets its claims with the claim event afterwards, as for any account.
- Tests: `tests/Unit/Service/Identity/NextcloudAccountProvisionerTest.php` runs the real event,
  listener and provisioner with the register, the user manager and the portals as doubles.

## Impact

- One new class; two optional, trailing constructor arguments on the event (existing dispatchers
  are unchanged); one branch in the listener. No schema change: the account carries no
  `identityType`, as an address-only account does, because the `nextcloud` mode looks it up by
  `subjectRef`.
- Two parallel calls for the same new user could both pass the lookup; the dispatcher is an
  install command run once, and a second identical row would be refused by nothing. Noted, not
  locked (the account space has no lock yet; see the claim review M2).
