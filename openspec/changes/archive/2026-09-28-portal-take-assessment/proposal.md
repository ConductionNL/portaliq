---
kind: code
---

# Proposal: portal-take-assessment

## Summary

A pupil can take a timed test in the portal. Portaliq adds one contribution
primitive, a `timedTask` collection: a list of attempts, a start action, a
question screen for the item types learniq's Assessment supports, answers saved
per question, a countdown to a server-given deadline that already includes
extra time, submit, and a read-only result once the school releases it. Every
step goes through the existing signed endpoint-action forward, so learniq keeps
every rule (window, access code, extra time, immutability, scoring) and
portaliq only carries and shows.

## Motivation

Recon C (`learniq-mi/learniq/_round2/recon/C-tests-grading-assignments.md`),
section 1: "Taking a test via portaliq does not exist today, full stop."
Nothing in the student manifest references `Assessment` or `AssessmentResult`,
and portaliq's frontend has no timer, no question flow and no autosave.
`TakeAssessmentView.vue` works, but only under a Nextcloud login. Section 4 names
this change (L) and section 5 question 1 asked Ruben whether to build it; D15
answered: portal file upload first, portal test taking in wave 2 as its own
change. This is that change.

Measured while designing it: learniq's `AssessmentAttemptGateListener` and
`AssessmentResultIntegrityListener` treat a write with no Nextcloud user as
system context and let it through. A portal write carries no Nextcloud user.
So a portal path that wrote `AssessmentResult` objects directly would skip the
window, the access code and the immutability rules. The rules have to run in
learniq, behind endpoints the portal calls, which is why the design forwards
instead of writing.

Competitor evidence, recon C section 2: row 6.4 "Online assessments with
proctoring and accommodations" (Woots extra-time column, Moodle quiz
per-user time overrides), row 7.13 grade notifications to pupils and parents.

## Affected Projects

- [x] Project: `portaliq`: the `timedTask` collection kind and its normaliser,
  an opt-in `subjectField` stamp on endpoint actions, and the test screen in
  the portal SPA.
- [ ] Project: `learniq`: the endpoints and the contribution entries described
  in design.md, as its own follow-up change (not in this PR).

## Scope

### In Scope

- `kind: timedTask` on a collection with a `timedTask` block naming five
  endpoint actions of the same contribution: `available`, `start`, `answer`,
  `submit`, `result`. A block that names a missing or non-endpoint action is
  dropped and the collection stays an ordinary list.
- `subjectField` on an endpoint action: portaliq resolves the action's scope
  (its `scopeClaim`, else the subject) and stamps it into the forwarded body
  under that name, overriding any client value; unresolvable is 403 with no
  forward. This hands learniq the learner's `learnerRef` without trusting the
  browser.
- The portal screen: available tests and past attempts, start (with an access
  code when asked), one question at a time with navigation, renderers for
  choice, inline choice, text entry, extended text, order and match, a text
  fallback for hotspot and gap match, autosave per question, a countdown to
  `deadlineAt` corrected for clock skew, auto-submit at zero, submit with a
  confirmation, and a read-only result view.
- The payload contract between portaliq and a leaf app, in design.md.

### Out of Scope

- Learniq's endpoints and manifest entries (its own change; the list is in
  design.md and the PR body).
- Proctoring, tab locking and lockdown browsers: learniq's native test mode
  stays in its own screen (plan assumption A7: no third-party proctoring).
- Offline answering. An answer that fails to save is shown as not saved and
  retried on the next change or on navigation.

## Approach

Reuse the A6 endpoint-action forward (`POST /portal/api/actions/{app}/{action}`)
for every step: it already checks the subject's own manifest, `minTrust`,
instance-local endpoints and field whitelists, and it signs `X-Portal-Subject`.
Add a normaliser for the block, the `subjectField` stamp in the forward, and a
React view that drives the five actions.

## New Dependencies

None.

## Impact

- `lib/Contribution/TimedTaskConfigNormaliser.php` (new), called from
  `PortalManifestNormaliser`; `ActionConfigNormaliser` validates `subjectField`.
- `lib/Controller/ContributionController.php::action()` stamps `subjectField`.
- `src/portal/components/TimedTaskView.jsx`, `TimedTaskItem.jsx` (new),
  `src/portal/lib/timedTask.js` (new), `PageView.jsx`, `portalApi.js`, i18n.

## Cross-Project Dependencies

Learniq consumes the contract. Until learniq ships its side, nothing declares a
`timedTask` collection and nothing changes for any subject.

## Risks

### Risk 1: A client clock or a lost tab changes the time left
**Severity:** High. **Mitigation:** the deadline is the server's; the portal
counts down from `deadlineAt` using `serverNow` to correct skew, and learniq
refuses answers after the deadline. The countdown is presentation, not
enforcement.

### Risk 2: Rules skipped because the write has no Nextcloud user
**Severity:** High. **Mitigation:** portaliq never writes an attempt. Every
step is a learniq endpoint; the contract makes learniq enforce the window,
access code, deadline and immutability on that path (design.md, "What learniq
adds").

### Risk 3: Answers lost on a flaky connection
**Severity:** Medium. **Mitigation:** each answer saves on change (debounced)
and on navigation; a failed save is marked on the question and retried; submit
first flushes unsaved answers.

## Rollback Strategy

Revert the PR. No data or schema changes; a contribution that declared a
`timedTask` collection falls back to an ordinary list.

## Open Questions

- When does learniq release a result to the pupil: on `graded`, or when a
  teacher publishes grades? Provisional: learniq decides and returns
  `released: false` until then; portaliq shows whatever `result` returns.
