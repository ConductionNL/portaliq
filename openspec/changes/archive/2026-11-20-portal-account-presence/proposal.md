# Proposal: portal-account-presence

## Why

Board DqContact (dossiq) lists how a person can be reached: "Mijn Zuiddrecht, berichtenbox" when the person has a
portal account. dossiq does not read portaliq's accounts: its users are not portaliq staff, and the register is
closed to them. The coordinator decided on 24 Oct:

- portaliq answers whether a person has a portal account;
- the answer is read-only, with a permission check;
- dossiq shows the row.

## What changes

- `PortalAccountLookup::activeByCitizenNumber($bsn)`: the active DigiD or eIDAS account whose identity reference is the
  citizen's number, in any tenant. Pending, suspended, void and removed accounts do not count.
- `PortalAccountPresence::forCitizen($bsn)`: `{allowed, hasAccount, portalName}`. Nothing else about the account is
  given (no address, no dates, no identifiers).
  - The permission check runs first: a signed-in Nextcloud user who is not a portal citizen. A citizen's own session
    may not ask about others.
  - The calling app decides whether its user may see the person at all (dossiq reads the person under the user's own
    rights first).

It is an in-process service for apps on the same instance; no HTTP route is added.

## Scope

- `lib/Service/Identity/PortalAccountLookup.php`, `lib/Service/Identity/PortalAccountPresence.php`.
