# Tasks: portal-take-assessment

Kind: code. Recon C change row `portal-take-assessment`; decision D15 (wave 2).

## Implementation Tasks

### Task 1: The timedTask block normaliser
- **spec_ref**: `openspec/changes/portal-take-assessment/specs/portal-contribution-contract/spec.md#requirement-a-collection-must-be-able-to-declare-a-timed-task-driven-by-five-endpoint-actions`
- **files**: `lib/Contribution/TimedTaskConfigNormaliser.php`, `lib/Contribution/PortalManifestNormaliser.php`, `tests/Unit/Contribution/TimedTaskConfigNormaliserTest.php`
- **acceptance_criteria**:
  - GIVEN five endpoint actions WHEN normalised THEN the block is kept; otherwise kind and block are dropped
- [x] Implement
- [x] Test

### Task 2: The subjectField stamp
- **spec_ref**: `openspec/changes/portal-take-assessment/specs/portal-contribution-contract/spec.md#requirement-an-endpoint-action-must-be-able-to-receive-the-subjects-scope-from-the-server`
- **files**: `lib/Controller/ContributionController.php`, `lib/Contribution/ActionConfigNormaliser.php`, `tests/Unit/Controller/ContributionControllerSubjectFieldTest.php`
- **acceptance_criteria**:
  - GIVEN subjectField WHEN forwarded THEN the resolved scope overrides the client; unresolvable is 403 with no call
- [x] Implement
- [x] Test

### Task 3: The pure timed-task module
- **spec_ref**: `openspec/changes/portal-take-assessment/specs/portal-contribution-contract/spec.md#requirement-the-portal-must-let-a-subject-take-a-timed-task`
- **files**: `src/portal/lib/timedTask.js`, `src/portal/lib/portalApi.js`, `tests/timed-task.spec.mjs`
- **acceptance_criteria**:
  - GIVEN a skewed clock WHEN counting down THEN the server deadline rules
  - GIVEN rapid changes WHEN saving THEN only the latest value per question is sent, in order
- [x] Implement
- [x] Test

### Task 4: The portal screen
- **spec_ref**: `openspec/changes/portal-take-assessment/specs/portal-contribution-contract/spec.md#requirement-the-portal-must-let-a-subject-take-a-timed-task`
- **files**: `src/portal/components/TimedTaskView.jsx`, `src/portal/components/TimedTaskItem.jsx`, `src/portal/components/PageView.jsx`, `src/portal/i18n/`, `tests/timed-task.spec.mjs`, `package.json`
- **acceptance_criteria**:
  - GIVEN a timedTask collection WHEN the page renders THEN tasks, attempts and the question screen work as specified
- [x] Implement
- [x] Test

### Task 5: Docs
- **spec_ref**: `openspec/changes/portal-take-assessment/design.md#the-contribution-contract`
- **files**: `docs/operations/timed-tasks-in-the-portal.md`, `README.md`
- **acceptance_criteria**:
  - GIVEN a leaf app author WHEN reading the docs THEN the manifest keys and payloads are described
- [x] Implement

## Verification
- [x] `openspec validate portal-take-assessment` passes, diff checks green, `composer check:strict` run once (its only red is the 29 inherited PHPUnit errors)

## Quality checklist

- PHPUnit for the normaliser and the stamp; `node --test` for the module and renderers
- English and Dutch strings for every new portal string
