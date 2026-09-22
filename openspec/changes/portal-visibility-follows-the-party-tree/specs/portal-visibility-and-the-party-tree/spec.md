---
status: proposed
---

# Spec: portal-visibility-and-the-party-tree

**Status:** proposed
**Scope:** portaliq (owner); openregister owns the party tree, the case app declares whether its cases may be reached through a parent
**Depends on:** `portal-identity-and-the-organisations-cases` (the mandate and the flat organisation scope); `portal-contribution-contract` (the scoping); `supplier-portal` (the organisation record)

## Purpose

A holding company sees the cases of the entities below it, on one mandate,
because the group is already recorded as a tree. Nobody maintains eleven
grants by hand. Requested by the dossiq competitor analysis, ledger row
13.38.

## ADDED Requirements

### Requirement: A mandate declares how far down the tree it reaches (REQ-PTV-001)

A mandate SHALL carry a reach of either the organisation it names, or that
organisation and the entities below it in the party tree. The default
SHALL be the organisation it names. A mandate without the wider reach
SHALL grant nothing on any other entity.

#### Scenario: One mandate covers the group
- **GIVEN** a parent organisation with two subsidiaries and one mandate that reaches down
- **WHEN** the mandated identity opens their case list
- **THEN** the cases of the parent and of both subsidiaries are listed
- e2e: `tests/e2e/portal-visibility-follows-the-party-tree.spec.ts`

#### Scenario: A flat mandate stays flat
- **GIVEN** the same group with a mandate that names only the parent
- **WHEN** the mandated identity opens their case list
- **THEN** only the parent's cases are listed
- e2e: `tests/e2e/portal-visibility-follows-the-party-tree.spec.ts`

#### Scenario: A sibling is not below
- **GIVEN** two organisations under one parent, and a mandate on the first that reaches down
- **WHEN** the mandated identity opens their case list
- **THEN** the second organisation's cases are not listed
- @e2e exclude Scope resolution; covered by PHPUnit

### Requirement: The case app decides whether its cases may be reached through a parent (REQ-PTV-002)

A case SHALL be reachable through a parent mandate only where the
contribution declares its type reachable that way. A type that declares
nothing SHALL NOT be reachable through the tree, whatever the mandate
says.

#### Scenario: A case type that refuses the tree stays with its own entity
- **GIVEN** a subsidiary with a case whose type is not declared reachable through a parent
- **WHEN** the parent's mandated identity opens their case list
- **THEN** that case is not listed
- e2e: `tests/e2e/portal-visibility-follows-the-party-tree.spec.ts`

#### Scenario: Silence is a refusal
- **GIVEN** a case type whose declaration says nothing about parent access
- **WHEN** the scope is resolved for a mandate that reaches down
- **THEN** its cases are excluded
- @e2e exclude Fail-closed default; covered by PHPUnit

### Requirement: The scope is resolved from the party tree, which portaliq does not keep (REQ-PTV-003)

The portal SHALL resolve the entities a mandate reaches by reading the
party relations OpenRegister holds, at the time of the request. It SHALL
NOT store a hierarchy of its own and SHALL NOT serve a case list from a
hierarchy cached beyond the request.

#### Scenario: A subsidiary sold today is gone today
- **GIVEN** a parent whose mandate reaches down, and a subsidiary removed from the group
- **WHEN** the mandated identity opens their case list
- **THEN** the former subsidiary's cases are no longer listed, with no grant revoked
- e2e: `tests/e2e/portal-visibility-follows-the-party-tree.spec.ts`

#### Scenario: A subsidiary acquired today is visible today
- **GIVEN** the same parent, and a new entity added below it
- **WHEN** the mandated identity opens their case list
- **THEN** that entity's cases are listed, with no grant written
- e2e: `tests/e2e/portal-visibility-follows-the-party-tree.spec.ts`

### Requirement: The walk over the tree is bounded (REQ-PTV-004)

The resolution SHALL carry a maximum depth and a page size, both
configurable. Where a group exceeds the bound the portal SHALL refuse the
listing with an explanation naming the bound, and SHALL NOT return a
partial list presented as complete.

#### Scenario: A deep group is refused, not truncated
- **GIVEN** a group deeper than the configured maximum depth
- **WHEN** the mandated identity opens their case list
- **THEN** the listing is refused and the bound is named
- @e2e exclude Bound behaviour with a synthetic deep group; covered by PHPUnit

#### Scenario: No unbounded read reaches OpenRegister
- **GIVEN** any scope resolution over the party tree
- **WHEN** the relations are read
- **THEN** every read carries a limit
- @e2e exclude Query shape; covered by PHPUnit

### Requirement: A case reached through the tree names its entity and its mandate (REQ-PTV-005)

Every case listed through a parent mandate SHALL name the entity it
belongs to and the mandate that reaches it. The portal SHALL NOT present a
subsidiary's case as the parent's own.

#### Scenario: The list says whose case it is
- **GIVEN** a parent's case list carrying a subsidiary's case
- **WHEN** it is listed
- **THEN** the subsidiary's name and the mandate that reaches it are shown beside it
- e2e: `tests/e2e/portal-visibility-follows-the-party-tree.spec.ts`

#### Scenario: The case still belongs to the subsidiary
- **GIVEN** a case filed by a subsidiary and read through the parent
- **WHEN** the case is opened
- **THEN** the requester on the case is still the subsidiary
- e2e: `tests/e2e/portal-visibility-follows-the-party-tree.spec.ts`

### Requirement: Acting for an entity below the mandate is recorded (REQ-PTV-006)

An identity whose mandate reaches down SHALL be able to act for an entity
below the one the mandate names, where the case allows it, and the switch
SHALL be offered in the same place as any other organisation switch. Every
write made that way SHALL record the entity acted for and the mandate it
was made under.

#### Scenario: The switcher offers the subsidiary
- **GIVEN** an identity with one mandate that reaches down over two subsidiaries
- **WHEN** they open the organisation switcher
- **THEN** both subsidiaries are offered
- e2e: `tests/e2e/portal-visibility-follows-the-party-tree.spec.ts`

#### Scenario: The write names the entity and the mandate
- **GIVEN** the same identity acting for a subsidiary
- **WHEN** they write on a case
- **THEN** the write records the subsidiary and the mandate on the parent
- e2e: `tests/e2e/portal-visibility-follows-the-party-tree.spec.ts`
