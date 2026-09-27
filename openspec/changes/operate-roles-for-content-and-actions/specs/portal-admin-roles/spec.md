---
status: proposed
---

# Spec: portal-admin-roles

## Purpose

An administrator decides which groups may do which sensitive action, so a
service desk or a communication team can work in portaliq without being
Nextcloud administrators. Portaliq matrix rows `ops-action-auth-rbac` and
`cmp-ops-rbac`.

## ADDED Requirements

### Requirement: An administrator grants an action to a group on screen (REQ-ORA-001)

The admin settings SHALL show an "Actions" section listing every action
portaliq checks, each with its label, its description and a group picker.
Saving SHALL store the chosen groups for that action. Only an instance
administrator SHALL be able to read or change it.

#### Scenario: A service desk may provision accounts
- **GIVEN** an administrator and a group "Service desk"
- **WHEN** they open the admin settings, find "Create and manage portal accounts" under "Actions", pick "Service desk" and leave the page
- **THEN** a member of "Service desk" who is not an administrator can provision a portal account
- e2e: `tests/e2e/operate-roles-for-content-and-actions.spec.ts`

#### Scenario: A non-administrator cannot change the grants
- **GIVEN** a signed-in user who is not an instance administrator
- **WHEN** they call `PUT /api/settings/actions`
- **THEN** the request is refused and the matrix is unchanged
- @e2e exclude Server-side refusal; pinned by ActionSettingsControllerTest

### Requirement: The grants accept only known actions and existing groups (REQ-ORA-002)

Saving the grants SHALL drop an action that is not in the catalogue and a
group id that does not exist, and SHALL keep every other grant.

#### Scenario: A made-up group is dropped
- **GIVEN** a save that grants `portal.provision` to `["Service desk", "ghost"]`
- **WHEN** it is stored
- **THEN** the stored entry is `["Service desk"]`
- @e2e exclude Input sanitising; pinned by ActionSettingsControllerTest

### Requirement: The seed names every checked action (REQ-ORA-003)

`lib/actions.seed.json` SHALL list every action name passed to
`requireAction()` in `lib/`, each with a label and a description, and SHALL
carry no template comment. A test SHALL fail when code checks an action the
seed does not list.

#### Scenario: A new action without a seed entry fails the build
- **GIVEN** a controller that calls `requireAction()` with `portal.export`
- **WHEN** the unit suite runs and the seed has no `portal.export`
- **THEN** `ActionSeedCensusTest` fails naming it
- @e2e exclude Build-time census

### Requirement: An upgrade adds new actions without overwriting grants (REQ-ORA-004)

The repair step SHALL add each seed action missing from the stored matrix
with its seed groups, and SHALL NOT change any stored entry.

#### Scenario: A grant survives an upgrade
- **GIVEN** an installation where `portal.provision` is granted to "Service desk" and the new release adds `portal.export` to the seed
- **WHEN** the upgrade runs the repair step
- **THEN** `portal.provision` is still granted to "Service desk" and `portal.export` is listed as "Only administrators"
- @e2e exclude Repair step; pinned by InitializeActionsTest

### Requirement: Page editors can edit the menu (REQ-ORA-005)

The editor groups SHALL govern writes to the `menu` schema as they govern
writes to `page`.

#### Scenario: An editor links a new page
- **GIVEN** a member of a page editor group who is not an administrator
- **WHEN** they publish a page and add it to the site menu
- **THEN** the menu entry is saved and shows on the site
- e2e: `tests/e2e/operate-roles-for-content-and-actions.spec.ts`
