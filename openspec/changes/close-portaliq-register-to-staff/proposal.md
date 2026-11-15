# Close the portaliq register to staff

## Why

A live probe on the Zuiddrecht demo instance (2026-10-14) showed that any signed-in Nextcloud account could read portaliq's resident and staff records through OpenRegister's objects API. An account in no group and a self-registered account in the resident group `inwoners` both read 6 access requests (other residents' names and reasons), 24 submissions, 53 portal accounts, 117 portal messages and 61 notifications. The same account opening `/apps/portaliq/` was shown the staff dashboard.

The cause is the shipped authorization: 44 schema read rules named `authenticated`, and the register had no block. OpenRegister uses a schema's own block in place of the register's, so a register block alone would have closed nothing.

The demo lets anyone self-register a Nextcloud account, so this has to be closed before it goes public.

## What changes

- The register gets an authorization block that names only the staff group `portaliq-medewerkers` for read, create, update and delete. A schema added later without a block is closed the day it ships.
- Every schema rule that named `authenticated` names `portaliq-medewerkers` instead. `export` follows `read`. The public site content keeps its public rules: `page` while published, `menu` and `glossaryTerm`.
- The register version moves 0.96.0 to 0.97.0, because OpenRegister writes a register's own fields only when its version is newer. Schema blocks are picked up by OpenRegister's content diff.
- A repair step `SeedStaffGroup` creates `portaliq-medewerkers` and, once, adds the members of the configured editor groups and of every group the action matrix names (never `admin`, never the registration app's group), so nobody who already worked in portaliq loses the records on the upgrade.
- The configured editor groups are added to the `read` rule of the schemas they edit (page, media, menu, portalNotice, sharedBlock), and leave it when they are removed from the setting, so an editor outside the staff group still reads the drafts they edit.
- `/apps/portaliq/` answers a signed-in account that is neither a resident nor staff with a 403 instead of the staff dashboard. Residents are still sent to their portal first.

Portal flows are unaffected: residents reach their data through portaliq's portal API with a portal session, and every OpenRegister call on those paths passes `_rbac: false`. A test now holds that to a reviewed list.

## Out of scope

- `MessageStaffController` answers any signed-in account, but only with threads addressed to that account's own uid; it is not a cross-user read.
