---
status: proposed
---

# Spec: portal-tenancy

## ADDED Requirements

### Requirement: A portal read or write runs as its organisation (REQ-PAO-001)

A read or write that portaliq makes for a portal subject SHALL run inside OpenRegister's act-for-an-organisation context for the subject's portal organisation, when OpenRegister offers that context. The portal organisation slug SHALL map to the organisation uuid through the portal organisation lookup. Reads and writes without an organisation, and anonymous reads and submissions, SHALL run as before.

#### Scenario: A resident reads their own rows
- **GIVEN** a resident of the portal of organisation `gemeente-a`
- **WHEN** portaliq reads a collection, a page or one row for them, including the account claim and via reads it needs
- **THEN** every OpenRegister call runs acting for the uuid of `gemeente-a`
- @e2e exclude Context wiring; pinned by PortalReadsActForTheOrganisationTest

#### Scenario: A resident creates, changes or deletes a row
- **GIVEN** the same resident
- **WHEN** portaliq creates, updates or deletes a row for them
- **THEN** the ownership re-read and the write run acting for the uuid of `gemeente-a`
- @e2e exclude Context wiring; pinned by PortalWritesActForTheOrganisationTest

### Requirement: An organisation the portal cannot resolve reads and writes nothing (REQ-PAO-002)

When the subject's organisation slug names no organisation, portaliq SHALL make no OpenRegister call for that read or write, and SHALL answer as for an unavailable store.

#### Scenario: A slug that names no organisation
- **GIVEN** a subject whose organisation slug does not resolve
- **WHEN** portaliq reads or writes for them
- **THEN** nothing is read or written, and the read is empty
- @e2e exclude Fail-closed branch; pinned by PortalTenantContextTest and PortalReadsActForTheOrganisationTest

### Requirement: Claim-addressed recipients are found in the record's organisation (REQ-PAO-003)

For a change rule that names its recipients by an account claim, portaliq SHALL list the accounts acting for the changed record's organisation. A record without an organisation SHALL list as before.

#### Scenario: A school record names a guardian reference
- **GIVEN** a record of organisation `school-a` that holds a guardian reference
- **WHEN** a claim-addressed rule looks for the accounts holding that reference
- **THEN** the accounts are listed acting for `school-a`
- @e2e exclude Context wiring; pinned by PortalAccountsByClaimOrganisationTest

### Requirement: Existing rows get their organisation from a reported dry run, then an explicit apply (REQ-PAO-004)

`occ portaliq:tenancy:restamp` SHALL list the rows of every organisation-scoped portaliq schema and report, per organisation, how many already carry it and how many would be restamped, plus every row whose organisation slug is missing or names no organisation. It SHALL write nothing unless `--apply` is given. With `--apply` it SHALL set `@self.organisation` to the uuid of the organisation the row's `organisation` names, only for rows whose slug resolves and whose stored organisation differs. It SHALL never derive an organisation any other way.

#### Scenario: The dry run reports and writes nothing
- **GIVEN** rows with a resolving slug and the default organisation, rows already restamped, and rows without a slug or with an unknown slug
- **WHEN** the command runs without `--apply`
- **THEN** it prints the counts per organisation and lists the unresolved rows, and no row is written
- @e2e exclude Command over doubles; pinned by PortalOrganisationRestampTest and TenancyRestampTest

#### Scenario: The apply restamps only what resolves
- **GIVEN** the same rows
- **WHEN** the command runs with `--apply`
- **THEN** only the rows with a resolving slug and another stored organisation get the organisation uuid, and a failed write makes the command exit 1
- @e2e exclude Command over doubles; pinned by PortalOrganisationRestampTest and TenancyRestampTest
