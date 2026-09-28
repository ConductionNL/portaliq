---
kind: code
---

# Proposal: portal-scope-list-membership

## Summary

A portal subject can own a row through a list field. Today portaliq compares
the direct scope field as one value, so a row whose scope field is a list never
matches and reads empty. This change makes the ownership check match when the
stored value is a list that contains the subject's scope value, keeps the
single-value match exactly as it is, and fails closed on every other shape. It
covers the list read, the single-object read, the verified update (and so the
file upload of PR 745, which goes through both), and the create stamp.

## Motivation

learniq already declares list scope fields and gets nothing back:

- `studentSubmissions` and `createSubmission` scope `submission` by
  `learnerRefs`, which the learniq register types as `array`
  (`lib/Settings/learniq_register.json`, `Submission.learnerRefs`: "the portal
  matches the student subject's LearnerProfile UUID against this array
  (membership)").
- `parentChildren`, merged today in learniq PR 928
  (`portal-contribution-guardian-audiences`), scopes `learner-profile` by
  `guardianRefs`, also an array. Its `design.md` states the design assumes
  portaliq does "the DIRECT array-containment match".

Both read empty in the portal. The cause is one line, twice:
`PortalObjectReader::verifyScope` and `PortalObjectWriter::fetchOwnedObject`
both compare `(string)($row[$scopeField] ?? '')` with the scope value. A PHP
array casts to the string `"Array"`, so the check drops every list-scoped row.
OpenRegister itself already filters an `array` property by containment
(`MagicSearchHandler::applyJsonArrayFilter`, `@>`), so the query returns the
right rows and portaliq's own per-row check throws them away.

The previous lane logged this as a cross-repo finding
(`/home/rubenlinde/memcap-work/lq-lanes/pq-guard/LANE-LOG.md`, "Finding for
the orchestrator"). The reverse `via` join already matches a list element
(`rowInTargetSet`, contract v2.2), so the direct path is the one gap.

## Affected Projects

- [ ] Project: `portaliq` — the direct scope check in the reader and the writer
  matches list membership; the update keeps a verified list; the create stamps
  a one-element list for an `array` scope field.

## Scope

### In Scope

- One shared scope match used by `verifyScope` (list read, single read, the
  portalAccount lookup) and `fetchOwnedObject` (verified update): a single
  string or integer value matches when equal; a list matches when at least one
  string or integer element equals the scope value; an empty scope value, an
  absent or null value, an empty list, an associative array and any other
  shape never match.
- The verified update re-stamps the stored list it just verified instead of
  overwriting it with the single subject ref, so a patch never removes the
  other members and never moves the row out of scope.
- The create stamps `[subjectRef]` when the target schema declares the scope
  field as `type: array`, and the single value as today otherwise or when the
  schema cannot be read.
- Unit tests for the matrix the brief names: a list that contains the ref
  passes, a list that does not fails, a single value still works, an empty list
  fails; on reads and on writes.

### Out of Scope

- The file upload endpoint itself lives only on PR 745
  (`feat/assignment-portal-file-upload`). It proves ownership with
  `PortalObjectReader::readObject` and writes with
  `PortalObjectWriter::updateObject`, so it inherits this change with no edit.
- Honouring `scopeClaim` on create. The create stamps the subject's own
  `subjectRef` today, and so does the upload's create path; changing that is a
  separate contract decision.
- learniq's `Submission.required` (`learnerIds`, `tenant_id`), which the portal
  create does not send. That is a learniq schema question, logged in PR 745.
- `ProposalQueueReader` and the other single-purpose readers that compare
  their own fixed fields.

## Approach

A small trait, `PortalScopeMatch`, holds the one match rule, so the reader and
the writer cannot drift apart. `verifyScope` and `fetchOwnedObject` call it in
place of the string cast. The writer takes an optional `PortalSchemaReader`
(the precedent `ActionConfigNormaliser` already set) to decide the create
stamp's shape. Details in `design.md`.

## New Dependencies

None.

## Impact

- `lib/Service/PortalScopeMatch.php` (new trait).
- `lib/Service/PortalObjectReader.php`: `verifyScope`.
- `lib/Service/PortalObjectWriter.php`: `createObject`, `updateObject`,
  `fetchOwnedObject`, constructor.
- `README.md`: the contract v2 section names the list rule.
- Every caller of those methods inherits the rule: the contribution list,
  detail, update, mark-read, file list and download, status transitions, the
  cross-reference guard, and the upload of PR 745.

## Cross-Project Dependencies

learniq depends on this to make `studentSubmissions`, `createSubmission` and
`parentChildren` return rows. learniq needs no change. No other app declares a
list scope field today; a single-value scope field behaves exactly as before.

## Risks

### Risk 1: A list match widens access

**Severity:** High — **Mitigation:** the match is strict membership of the
subject's own server-resolved scope value; it never matches on an empty value,
an empty list, an associative array, a nested list or a loosely equal value.
The query-side filter is unchanged and the per-row check stays the boundary.
Tests pin each fail-closed shape.

### Risk 2: An update overwrites a shared list

**Severity:** Medium — **Mitigation:** for a verified list the update re-stamps
the stored list itself, which already contains the subject; a client value in
the patch never wins.

### Risk 3: The create stamp reads the wrong schema

**Severity:** Low — **Mitigation:** `PortalSchemaReader` looks up by slug. A
wrong shape only makes OpenRegister reject the write (fail closed); either
shape holds only the subject's own ref.

## Rollback Strategy

Revert the PR. The single-value behaviour is unchanged, so a revert only
returns list-scoped collections to empty.
