# Test Plan: portal-take-assessment

No live run: no leaf app declares a timed task until learniq ships its side,
the browser servers are down in this lane, and the lane may not touch the
shared instance. Every scenario is pinned at the seam where it is decidable.

## Test Cases

### TC-1: A sound timed task is kept; a broken one falls back to a list
- **spec_ref**: `openspec/changes/portal-take-assessment/specs/portal-contribution-contract/spec.md#requirement-a-collection-must-be-able-to-declare-a-timed-task-driven-by-five-endpoint-actions`
- **type**: regression
- **steps**: normalise a sound block; a block with a missing key, a trust-dropped action, a non-endpoint action, a non-array block
- **expected result**: kept; each broken one loses `kind` and `timedTask`
- **test command**: `vendor/bin/phpunit --filter TimedTaskConfigNormaliserTest`

### TC-2: subjectField stamps the resolved scope over the client value
- **spec_ref**: `...#requirement-an-endpoint-action-must-be-able-to-receive-the-subjects-scope-from-the-server`
- **type**: security
- **steps**: forward with a smuggled `learnerRef`; forward with an unresolvable claim; forward without `fields`; malformed `subjectField`
- **expected result**: resolved value sent; 403 and no call; stamp-only body; action dropped
- **test command**: `vendor/bin/phpunit --filter 'ContributionControllerSubjectFieldTest|TimedTaskConfigNormaliserTest'`

### TC-3: The attempt flow, the clock and the renderers
- **spec_ref**: `...#requirement-the-portal-must-let-a-subject-take-a-timed-task`
- **type**: functional
- **steps**: drive start, answers, submit against a fake api; compute the countdown with a skewed client clock; render every item type
- **expected result**: one send per latest value, flush before submit; 37:30 left; the right control per type
- **test command**: `node --test tests/timed-task.spec.mjs`

## Coverage Summary

All three requirements: TC-1 to TC-3.

## Out of Scope

Learniq's endpoints (its change), a live Playwright run (no consumer yet).
