---
kind: code
---

# Proposal: operate-roles-for-content-and-actions

## Why

An organisation wants its communication team to edit the site, a service
desk to provision resident accounts, and only the functional administrator
to change settings. Portaliq can already let a group edit pages. It can
already check an action against groups. But no screen lets an administrator
say which group may do which action, so every action stays admin-only.

Portaliq matrix, row `ops-action-auth-rbac`, "Restrict a sensitive admin
action to specific groups instead of all admins.", rated `partial`,
`built.state` `built`. Its `built.note`, verbatim:

> The mechanism is real and wired, but every shipped seed entry is ['admin'], admin-only out of the box, so there is no shipped example of a non-admin group actually granted an action. The seed file's own $comment is also unedited app-template boilerplate ('remove the $comment field... when implementing your app'), left in production.

Three competitors are rated `yes`. `open-inwoner`, verbatim:

> src/open_inwoner/configurations/admin.py:387 SiteConfiguration fieldsets shown per Django permission; Django groups and permissions (src/open_inwoner/accounts/admin.py:222 GroupAdmin) [reached on Django admin]

`xxllnc-pip`, verbatim:

> backend/perl-api/db/upgrade/v2026.5.0/pre-1000-MINTY-16841_add_permissions_for_system_roles.sql; backend/perl-api/db/upgrade/v2026.5.0/pre-1000-MINTY-17320-export_permission.sql [reached on staff app]

`liferay-dxp`, verbatim:

> https://learn.liferay.com/w/dxp/security-and-administration/users-and-permissions/roles-and-permissions/defining-role-permissions 'you can fine-tune whether a role grants view access to various applications in the control panel or get more granular and only grant access to an application's co[nfiguration]'.

Portaliq matrix, row `cmp-ops-rbac`, "Give editors and administrators
separate rights over content and settings.", rated `partial`, `built.state`
`built`. Its `built.note`, verbatim:

> Content editing genuinely separates 'editor group' from 'admin'. Settings/action rights are mechanically group-based but every shipped action is admin-only, so out of the box there is no non-admin role over settings.

Three competitors are rated `yes`. `open-inwoner`, verbatim:

> Django groups and permissions (src/open_inwoner/accounts/admin.py:222); per-permission configuration sections (src/open_inwoner/configurations/admin.py:387); django-cms page permissions for editors [reached on Django admin and CMS toolbar]

`xxllnc-pip`, verbatim:

> system roles and rights backend/perl-api/db/upgrade/v2026.5.0/pre-1000-MINTY-16841_add_permissions_for_system_roles.sql; frontend-mono/apps/main/src/Routes.tsx:50 users [reached on staff app; no content editor role since there is no CMS]

`liferay-dxp`, verbatim:

> https://learn.liferay.com/w/dxp/security-and-administration/users-and-permissions/roles-and-permissions/default-roles-reference (Site Administrator, Site Owner, Site Member) and https://learn.liferay.com/w/dxp/security-and-administration/users-and-permissions/roles-and-permissions/defining-role-permissions.

The lane recorded both as `build` on two or more competitors rated `yes`.

## What changes

- **An "Actions" section in the admin settings.** Every action portaliq
  checks is listed with a plain label and what it lets someone do, and a
  group picker next to it. An administrator grants an action to a group
  there. Administrators always keep every action.
- **The seed describes portaliq's own actions.** `lib/actions.seed.json`
  loses the template comment, and every action carries a label and a
  description. Code checks three actions (`portal.provision`,
  `portal.ask-partner`, `portal.review-proposal`), and the seed lists the
  same three with no label a person could read.
- **New actions reach existing installations.** The repair step adds an
  action the seed gained since install, admin-only, and never overwrites a
  choice an administrator made. Today it seeds only an empty matrix.
- **Page editors can also place a page in the menu.** The editor groups that
  may edit pages also govern the `menu` schema, so the person who publishes
  a page can link it without asking an administrator.

## Rows this closes

| Matrix | Row id | Row name | Own rating | What is missing |
|---|---|---|---|---|
| portaliq | `ops-action-auth-rbac` | Restrict a sensitive admin action to specific groups instead of all admins. | partial | A screen to grant an action to a group, and a seed that names every action. |
| portaliq | `cmp-ops-rbac` | Give editors and administrators separate rights over content and settings. | partial | A non-admin role over settings, granted through that screen, and content rights that reach the menu. |

## Existing work it builds on

- `lib/Service/ActionAuthService.php` (ADR-023): `requireAction()`, `can()`,
  `getMatrix()`, `setMatrix()`. Only `lib/Repair/InitializeActions.php` calls
  `setMatrix()` today.
- `lib/Service/PageEditorService.php`: editor groups, written into the `page`
  schema's authorization block, and `mayEdit()`.
- `portal-page-designer` (spec): who may edit pages, configurable and
  enforced at the write.
- `settings-management` (spec), REQ-CFG-002: settings writes stay admin-only.

## Out of scope

- Delegated Nextcloud settings administrators. Settings writes stay with
  instance administrators, as REQ-CFG-002 requires.
- Per-object permissions inside OpenRegister. That is OpenRegister's RBAC.
- Roles for portal residents. Their rights are the contribution contract's.
