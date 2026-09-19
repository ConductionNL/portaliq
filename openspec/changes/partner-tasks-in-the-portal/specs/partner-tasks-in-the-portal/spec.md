---
status: proposed
---

# Spec: partner-tasks-in-the-portal

**Status:** proposed
**Scope:** portaliq (owner); the case app declares the action
**Depends on:** `portal-task-delivery` (surface, proxy, worker);
`portal-contribution-contract` (audiences, endpoint actions);
`portal-identity-space` (pre-provisioned accounts)

## Purpose

A handler asks an outside partner for something from the case; the
partner sees, answers and uploads in the portal; the answer lands on the
case. Requested by the dossiq competitor analysis, register row 3.19.

## ADDED Requirements

### Requirement: The task surface serves every audience (REQ-PTP-001)

The "Mijn taken" surface SHALL be reachable for every audience the
contribution registry discovers, and SHALL list only the tasks whose
`subjectRef` matches the session, for `supplier` and `partner` sessions
exactly as for `client` sessions. A partner session SHALL never see a
resident's task.

#### Scenario: A partner sees its own tasks and nothing else
- **GIVEN** a partner signed in through eHerkenning and one task addressed to its organisation, plus one task addressed to a resident
- **WHEN** the partner opens "Mijn taken"
- **THEN** the list holds the partner's task only
- e2e: `tests/e2e/partner-tasks.spec.ts`

### Requirement: A handler raises an ask from the case (REQ-PTP-002)

Portaliq SHALL offer the internal contribution action `ask-partner` with
`partner`, `title`, `description`, `dueAt` and `uploadRules`. On submit it
SHALL refuse a caller without write on the case, resolve the partner to a
`portalAccount` (existing, or pre-provisioned from a KvK number and email),
and create the engine task server-to-server as the handler with the case
as its object and the due date and upload rules frozen on it. The task
SHALL enter the delivery ledger like any portal task.

#### Scenario: A handler asks an advisory body for an opinion
- **GIVEN** a handler with write on a case and a partner account for the welstandscommissie
- **WHEN** the handler submits `ask-partner` with a title, a due date in 14 days and one required PDF
- **THEN** one engine portal task exists addressed to the partner's `subjectRef` with that due date, and one `portal-inbox` and one `mail` ledger row exist for it
- e2e: `tests/e2e/partner-tasks.spec.ts`

#### Scenario: A handler without write on the case is refused
- **GIVEN** a user with read only on the case
- **WHEN** that user submits `ask-partner`
- **THEN** the response is 403 and no task or account exists
- @e2e exclude authorization guard; covered by PHPUnit on the action handler

#### Scenario: An unknown partner is pre-provisioned
- **GIVEN** no account for KvK 12345678
- **WHEN** a handler submits `ask-partner` with that KvK number and an email
- **THEN** a `portalAccount` in `pending` exists for it, the task is addressed to it, and the mail row carries the login link
- @e2e exclude provisioning path; covered by PHPUnit on `PortalAccountService::provision()` and the action handler

### Requirement: The answer lands on the case (REQ-PTP-003)

Completing a partner task SHALL store the comment and uploads on the case
object through the existing completion endpoint, SHALL record which
account completed it, and SHALL mark the engine task done. Portaliq SHALL
NOT call the case app.

#### Scenario: The partner uploads the opinion
- **GIVEN** an open partner task requiring one PDF
- **WHEN** the partner completes it with a comment and the PDF
- **THEN** the case object carries the upload and the comment, the task is done, and the task names the completing account
- e2e: `tests/e2e/partner-tasks.spec.ts`
