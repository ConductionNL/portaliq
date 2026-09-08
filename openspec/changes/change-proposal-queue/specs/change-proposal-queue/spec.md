---
status: proposed
---

# Spec: change-proposal-queue

**Status:** proposed
**Scope:** portaliq (owner); any app with a contribution places the leaf
**Depends on:** `portal-contribution-contract` (endpoint actions, field projection); OpenRegister leaf registration (ADR-066)

## Purpose

A citizen or a colleague proposes a field change on a record. The proposal
queues on the record until a reviewer with write rights accepts or rejects
it. Portaliq owns the queue and the review surface. The owning app places
the leaf and sees an ordinary object update when a proposal is accepted.
Requested by the dossiq competitor analysis, finding B24.

## ADDED Requirements

### Requirement: A change proposal is a queued object on its subject (REQ-CPQ-001)

Portaliq SHALL declare a `changeProposal` schema with `subject`,
`proposedBy`, `channel`, `changes[]` (`property`, `currentValue`,
`proposedValue`), `note`, a lifecycle `queued`, `accepted`, `rejected`,
`withdrawn`, and `decidedBy`, `decidedAt`, `decisionReason`. `currentValue`
SHALL be a snapshot taken when the proposal is made.

#### Scenario: A proposal records what it saw
- **GIVEN** a case whose `applicantPhone` is `0612345678`
- **WHEN** a proposal for `applicantPhone` is made
- **THEN** the proposal stores `currentValue = 0612345678` and the proposed value, in `queued`
- @e2e exclude schema import and snapshot are backend invariants; covered by PHPUnit on `ProposalService::propose()`

### Requirement: Portal subjects and colleagues can propose, never edit (REQ-CPQ-002)

A portal subject SHALL propose through the contribution action
`propose-change`, limited to properties the contribution lists as
`proposable`, with `proposedBy` derived from the session. A logged-in user
with read but not write on the subject SHALL propose through
`POST /apps/portaliq/api/proposals`. A proposal naming a property not listed
as proposable SHALL be refused.

#### Scenario: A citizen proposes a new phone number
- **GIVEN** a citizen with a portal session on a case whose contribution lists `applicantPhone` as proposable
- **WHEN** the citizen submits `propose-change` for `applicantPhone`
- **THEN** a `queued` proposal exists with `channel = portal` and `proposedBy` from the session
- e2e: `tests/e2e/change-proposal-queue.spec.ts`

#### Scenario: A status field cannot be proposed
- **GIVEN** the contribution does not list `status` as proposable
- **WHEN** a citizen submits `propose-change` for `status`
- **THEN** the request is refused with 422 and no proposal exists
- @e2e exclude server-side allow-list; covered by PHPUnit on the action handler

### Requirement: Accept writes the subject as the reviewer (REQ-CPQ-003)

`ProposalService::accept()` SHALL refuse a reviewer without write rights on
the subject, SHALL write the proposed values to the subject through the
OpenRegister objects API as that reviewer in one update, and SHALL then
transition the proposal to `accepted`. When the subject's current value
differs from the snapshot, the review surface SHALL show both and require an
explicit confirm. `reject()` SHALL require a reason and SHALL touch only the
proposal. Portaliq SHALL NOT call the owning app.

#### Scenario: A handler accepts a proposal
- **GIVEN** a queued proposal and a reviewer with write on the case
- **WHEN** the reviewer accepts
- **THEN** the case carries the proposed value, its audit trail names the reviewer, and the proposal is `accepted`
- e2e: `tests/e2e/change-proposal-queue.spec.ts`

#### Scenario: A reviewer without write rights is refused
- **GIVEN** a queued proposal and a user with read only on the case
- **WHEN** that user tries to accept
- **THEN** the service answers 403 and the proposal stays `queued`
- @e2e exclude authorization guard; covered by PHPUnit on `ProposalService::accept()`

### Requirement: The queue is a leaf on the subject (REQ-CPQ-004)

Portaliq SHALL register `portaliq-change-proposals` (kind `data-provider`,
storage `app-local`, `list` of queued proposals for the host object and
`create` appending one for the calling user) and
`portaliq-change-proposal-queue` (kind `render-surface`, `widget` and `tab`
under one id) through `RegisterLeafProvidersEvent` and `registerIntegration()`.
Neither leaf SHALL invoke any action in the consuming app (ADR-066 decision 2).

#### Scenario: dossiq shows the queue on a case
- **GIVEN** dossiq places `portaliq-change-proposal-queue` on its case detail page and one proposal is queued
- **WHEN** a handler opens the case
- **THEN** the widget lists the proposal with its diff and offers accept and reject
- e2e: `tests/e2e/change-proposal-queue.spec.ts`

#### Scenario: Both halves agree
- **GIVEN** the leaves are registered
- **WHEN** gate-24 inspects the app
- **THEN** each id has a descriptor and a JS registration with the complete render pair
- @e2e exclude parity is checked mechanically by gate-24
