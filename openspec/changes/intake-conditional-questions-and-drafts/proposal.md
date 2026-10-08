---
kind: code
depends_on: [portal-intake-form-as-an-object, embedded-intake-form]
---

# Proposal: intake-conditional-questions-and-drafts

> Retargeted 2026-10-01 (`site-reaches-portal-parity`): new frontend work in this change lands in the Vue site `src/site/`, not in the React portal `src/portal/`, which is being retired.

## Why

A resident filling in a request form sees every question, including the ones
that do not apply to them, and a required question they should never have
been asked can stop them submitting. If they close the tab halfway, their
answers are gone.

Portaliq matrix, row `cmp-int-conditional`, "Fill in a form that shows or
hides questions based on earlier answers.", rated `no`, `built.state` `none`.
Three competitors are rated `yes`. `nl-portal`, verbatim:

> frontend/packages/user-interface/src/pages/TaskDetailsPage.tsx:28 Form.io with ProtectedEval for conditional logic; frontend/packages/user-interface/src/pages/TaskDetailsPage.tsx:194 [reached on /taken/taak/:id; only in task forms; was unknown from docs]

`xxllnc-pip`, verbatim:

> backend/perl-api/root/tpl/zaak_v1/nl_NL/plugins/pip/case/view.tt:37 zs-case-webform-rule-manager; backend/perl-api/lib/Zaaksysteem/Controller/Plugins/PIP.pm:204 rule engine active attributes per phase [reached on webform and PIP phase form; was partial from docs]

`liferay-dxp`, verbatim:

> https://learn.liferay.com/w/dxp/low-code/forms/form-rules 'Forms can be dynamic, where the answer to one question changes the rest of the form' with Show-Hide, Require and Jump to Page rules. The page warns the Forms application 'is in maintenance mode as of Liferay DXP 2024.Q4'.

Portaliq matrix, row `cmp-int-save-resume`, "Save a half-finished form and
continue later.", rated `no`, `built.state` `none`. Two competitors are
rated `yes`. `xxllnc-pip`, verbatim:

> backend/perl-api/lib/Zaaksysteem/Controller/Form.pm:981 submit_concept_case; backend/perl-api/root/tpl/zaak_v1/nl_NL/plugins/pip/index.tt:21 'Concepten' kept 30 days [reached on webform then /pip 'Concepten']

`liferay-dxp`, verbatim:

> https://learn.liferay.com/w/dxp/low-code/objects/creating-and-managing-objects/creating-objects 'Enable or disable draft mode for object entries. While enabled, users can save entries as a draft and finalize them at a later time.' [was unknown]

Both rows have no demand row. The lane recorded both as `build` on the rule
of two or more competitors rated `yes`.

## What changes

- **Questions appear and disappear as the resident answers.** Portaliq's
  form renderers honour the `visibleWhen` condition a published form already
  carries on a field, in its local mode. Portaliq evaluates it with
  nextcloud-vue's own predicate and invents no condition grammar
  (ADR-085).
- **The server agrees with the screen.** On submit, a field whose condition
  is false is neither required nor accepted, so a hidden answer never
  reaches a case and a hidden required field never blocks one.
- **A form with a condition portaliq cannot evaluate is refused, visibly.**
  A condition in `endpoint` or `source` mode makes the binding resolve to no
  form, with a reason the administrator sees in the binding preview.
- **A page to fill in a bound form.** A `/site` widget, `intakeForm`, renders
  the form a binding resolves to with nextcloud-vue's `CnFormPage`. No page
  calls `/portal/api/intake/form` today; this is the render half the open
  change `portal-intake-form-as-an-object` left without a page.
- **Save and continue later.** On the intake widget and the embedded form, a
  "Save and continue later" button stores the answers so far as an
  OpenRegister journey run. A signed-in resident finds their draft again when
  they open the same form. A visitor without an account gets a resume code
  once. Nothing is created as a case until they submit.

## Rows this closes

| Matrix | Row id | Row name | Own rating | What is missing |
|---|---|---|---|---|
| portaliq | `cmp-int-conditional` | Fill in a form that shows or hides questions based on earlier answers. | no | Rendering and server validation that honour a field's `visibleWhen`. |
| portaliq | `cmp-int-save-resume` | Save a half-finished form and continue later. | no | A save action, a resume path signed in and anonymous, and a stated retention, over OpenRegister's journey run. |

## Existing work it builds on

- `portal-intake-form-as-an-object` (open): the binding, its resolution
  (`PortalFormBindingResolver`), the validator (`PortalFormValidator`), the
  intake routes. Its tasks T03 and T09 are open, and no page calls its form
  route; this change adds the page it renders on.
- `embedded-intake-form` (open): the embed frame and `EmbeddedForm.jsx`, the
  one reachable renderer of a bound form today.
- ADR-085 (hydra): a form is manifest-v2 `form` config, conditions are
  `$defs.visibleWhen`, drafts are `journeyRun` objects, one renderer.
- nextcloud-vue: `CnFormPage` and `src/utils/visibleWhen.js`
  (`evaluateVisibleWhenLocal`), both on `development`.

## Out of scope

- Authoring conditions. Buildiq's form designer owns that (ADR-085 §6).
- Multi-form journeys, branching between forms and the review step.
  nextcloud-vue's `journey-runtime` and OpenRegister's
  `or-form-and-journey-registry` own them.
- Conditions on contribution create and update actions (`SchemaForm.jsx`
  over `fieldConfigs`). Those forms are not published forms.
- Drafts of an amendment to an existing case.

## Sibling halves

- **ConductionNL/openregister** owes the journey run API:
  `or-form-and-journey-registry` Task 2 (`JourneyRunService`) and Task 3
  (`JourneyRunController`, resume by account and by token), and the
  retention job. None of its tasks is checked on `development`. It also
  owes an answer to one question this change cannot settle: whether a run
  can start over a single published form with no authored journey. If it
  cannot, buildiq authors a one-step journey per bound form.
- **ConductionNL/nextcloud-vue** re-exports `evaluateVisibleWhenLocal` from
  its package root. Today it is exported from `src/utils/index.js` but not
  from `src/index.js`, so the React embed would import it by deep path.
