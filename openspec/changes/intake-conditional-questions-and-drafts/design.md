# Design: intake-conditional-questions-and-drafts

Read at portaliq `development` `eeda3fa`, nextcloud-vue `development`,
openregister `development` and hydra ADR-085.

## What exists

- `lib/Service/Intake/PortalFormBindingResolver.php:365-398` `fieldsOf()`
  passes each published field through with its keys intact, adding only
  `preset`. A field's `visibleWhen` already reaches the render payload.
- `lib/Service/Intake/PortalFormValidator.php:61-93` `validate()` requires
  every `required` field and accepts every declared field. It reads no
  condition. It serves both `PortalIntakeController::submit()` (line 211)
  and `PortalEmbedController` (line 196).
- `src/portal/components/EmbeddedForm.jsx:33,94` renders
  `payload.fields` as a flat list. It is the only renderer of a bound form.
- `appinfo/routes.php:264-267`: `/portal/api/intake/catalogue`, `/form`,
  `/submit`, `/status`. Nothing in `src/` calls `/form` or `/submit`, and
  `tests/e2e/portal-intake-form-as-an-object.spec.ts` uses only the `request`
  fixture.
- nextcloud-vue `src/utils/visibleWhen.js`: `evaluateVisibleWhenLocal(cond,
  data)` (line 230), ops `eq, neq, gt, gte, lt, lte, empty, notEmpty`
  (line 52), fail-safe to hidden. `CnFormPage` calls it on every change.
- openregister `or-form-and-journey-registry` (open, 0 of 10 checkboxes):
  `journeyRun`, `JourneyRunService`, `JourneyRunController`, resume by account
  or token, a retention job.

## D1. One grammar, and nextcloud-vue evaluates it

The condition is the field's `visibleWhen` in local mode:
`{field, op, value}` over the answers so far. On `/site` the `intakeForm`
widget mounts `CnFormPage`, which already evaluates it. The React
`EmbeddedForm.jsx` imports `evaluateVisibleWhenLocal` from nextcloud-vue and
calls it on every change; it does not reimplement it. ADR-085 makes a second
condition grammar a review-blocking finding.

## D2. The server evaluates the same predicate, pinned to the client's

The server cannot trust the browser to have hidden a field. `PortalFormValidator`
gains `lib/Service/Intake/VisibleWhenLocal.php`: local mode only, the same
eight ops, fail-safe to hidden. A field whose condition is false is skipped:
not required, and its answer dropped from `answers`. A field whose condition
names another hidden field is hidden too (evaluated in declared order).

This is a second evaluator of one grammar, which is how grammars drift. It is
pinned by a fixture file `tests/fixtures/visible-when-local.json` built from
the cases in nextcloud-vue's `tests/utils/visibleWhen.spec.js`, run by both a
PHPUnit test here and a `node --test` spec here against the imported JS
function. (Changed while building: the design said Vitest, and portaliq has
no Vitest. The node spec gives the predicate a window with empty storage and
no signed-in user before importing it, which is the reader of a public form.)

Built: three kinds of condition the screen can answer and the server cannot
replay make `isDecidable()` false, and the form is refused under D3 with
them: `endpoint` / `source`, a right-hand clock token (`@now`, `@today`,
`@today+7d` and kin, resolved in the resident's time zone), and
`appInstalled` (what a resident's page knows about installed apps). `@me` is
nobody on a public form on both sides, and `@object.<answer>` reads another
answer on both sides. One divergence is accepted: PHP decodes an empty JSON
object as an empty list, so a malformed `{"all": {}, "any": []}` reads as
`all` of nothing on the server and `any` of nothing on the screen; the
fixture leaves it out.
When openregister ships a server-side evaluator with the journey run API,
portaliq calls it and deletes this class.

## D3. A condition portaliq cannot evaluate refuses the form

`endpoint` and `source` modes make a request. A public form must not call an
arbitrary endpoint per keystroke, and the server cannot replay it. So
`fieldsOf()` marks a form carrying such a condition as `resolvesToNoForm`
with the reason `unsupportedCondition`, and `PortalBindingPreview` shows the
administrator: "This form uses a condition the portal cannot check. Change
it to a condition on another answer." The resident sees the ordinary "This
form is not available" state.

## D4. The page a bound form is filled in on

`src/site/components/IntakeFormBlock.vue`, widget `intakeForm`, added to
`PUBLIC_WIDGETS` in `src/site/components/WidgetGrid.vue`. Its authored prop is
the binding route; it calls `GET /portal/api/intake/form?route=`, mounts
`CnFormPage` with the returned fields, runs the portal challenge, and submits
through `POST /portal/api/intake/submit`, then shows the reference and links
to `/portal/api/intake/status`. It sends the bearer when `src/site/lib/authApi.js`
holds one, so the applicant block is prefilled server-side for a signed-in
resident as the resolver already does.

## D5. A draft is an OpenRegister journey run, never a portaliq object

No draft schema is added to `lib/Settings/portaliq_register.json`. A draft is
personal data before it is a record, and ADR-085 §2 puts it in `journeyRun`
with a declared retention and a purge job. Portaliq adds three routes that
call openregister's run service in-process, the way `PortalObjectReader`
calls `ObjectService` (portal subjects are not Nextcloud users):

- `POST /portal/api/intake/drafts`: save the answers so far for a binding.
  Signed in: the run's owner is the portal `subjectRef`. Anonymous: the
  response carries a resume code, once.
- `GET /portal/api/intake/drafts?route=`: the signed-in resident's own draft
  for that binding, or none.
- `POST /portal/api/intake/drafts/resume`: an anonymous resume by code. A
  wrong code and an unknown run get the same answer, as the openregister
  requirement "A run MUST be resumable without becoming an oracle" demands.

Only fields visible under D2 are saved. Submitting a draft goes through the
ordinary submit path and ends the run.

## D6. What the resident is told

- The button: "Save and continue later".
- Signed in: "We saved your answers. Open this form again to continue. We
  keep them until {date}."
- Anonymous: the resume code in a selectable field, and "Keep this code to
  continue. We keep your answers until {date}." Nothing is emailed.
- On reopening, signed in: "You have unsent answers from {date}. Continue or
  start again."

`{date}` is the run's expiry from its declared retention, never a portaliq
constant.

## Risks

- **The run API is not built.** Drafts wait on openregister Tasks 2 and 3.
  Conditions do not; D1 to D4 ship on their own.
- **A single form may not be runnable.** Named as a sibling question. If
  openregister requires a journey, buildiq authors one per bound form and the
  binding carries its reference.
- **An embed on a municipality's site keeps a code in a visitor's head.** The
  code is shown once and never stored in the frame's storage.

## What this deliberately does not do

- No condition authoring, no journeys, no review step.
- No draft of a contribution action form.
- No mail with a resume link.
