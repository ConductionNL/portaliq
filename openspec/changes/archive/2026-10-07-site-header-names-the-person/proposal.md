# Proposal: site-header-names-the-person

## Why

Ruben reviewed the primary-school parent portal recordings (2026-10-02). After signing in, the site header read "Ingelogd als 99930md1id…": the internal subject reference of the portal account. A parent cannot read that, and it is a key that should not be on screen at all.

## What changes

- `GET /portal/api/session` also answers `displayName`: the portal account's display name (set by provisioning, an invitation or the broker). A value equal to the subject reference is not a name and is answered as `''`.
- The site header (`loggedInAs` in `src/site/lib/accountArea.js`) reads "Logged in as {name}" ("Ingelogd als {name}") with that name, and a plain "Logged in" ("Ingelogd") when no name is known. It never falls back to the subject reference.
- The shared string "Logged in as {subjectRef}" becomes "Logged in as {name}" in English and Dutch.

## Not changed

- The account record and how a name gets there. A DigiD login carries no name, so an account that was never given one still reads "Ingelogd".
