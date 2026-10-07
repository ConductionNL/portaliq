# Proposal: admin-menu-follows-roles

## Why

Ruben reviewed the primary-school recordings (2026-10-02). A teacher (Nextcloud group `instructors`, not an administrator) opening portaliq saw the whole administration menu: Portals, Accounts, Invitations, Themes, Request forms, Reports and more. The server already refuses most of what those pages write, but offering them to a teacher is confusing and invites errors. A teacher needs News, which every signed-in staff member may author (staff-news-screen).

## What changes

- The app page hands the app four flags for the signed-in user (`AdminMenuAccess`, initial state `access`):
  - `admin`: a Nextcloud administrator.
  - `pages`: may edit portal pages (an administrator or a member of the configured editor groups, `PageEditorService::mayEdit`).
  - `accounts`: may provision portal accounts (action `portal.provision`).
  - `accessRequests`: may answer access requests (action `portal.answer-access-request`).
- Each menu entry names the flag it needs as a `visibleIf` predicate in `src/manifest.json`, which CnAppNav already evaluates against `manifest.runtime`:
  - everyone: Dashboard, News, Documentation;
  - `pages`: Portals, Media, Notices;
  - `accounts`: Accounts, Invitations;
  - `accessRequests`: Access requests;
  - `admin`: Submissions, Request forms, Themes, Store, Reports, Features & roadmap, Flows, Integrations.
- A router guard reads the same predicates: typing the address of a hidden page opens the dashboard instead.
- Without the flags (an older server, a failed lookup) the app shows only the entries every signed-in user may use.

## Not changed

- Every server-side check stays as it is. The menu hides what a role may not use; it grants nothing.
- Detail pages and wizards that no menu entry leads to are left to the server's checks.
