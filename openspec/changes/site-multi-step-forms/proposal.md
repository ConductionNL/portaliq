# Proposal: site-multi-step-forms

## Why

Ruben approved twelve site mockups on 2026-10-02. Two of them are forms: `DossiqWoo.dc.html` (a Woo request, step 2 of 4) and `LearniqAbsence.dc.html` (a guardian reports an absence, on a phone). Both follow the NL Design System form guidelines and the Open Formulieren flow. The site's forms do not.

What the site's three form renderers do on `development` (b150def5):

| | `IntakeFormBlock.vue` (published form) | `c/SchemaForm.vue` + `c/SchemaField.vue` (create and update actions) | `FormBlock.vue` (landing page form) |
|---|---|---|---|
| required marking | `*`, `aria-hidden` | `*`, `aria-hidden` | `*`, `aria-hidden` |
| errors | per field, under the field | one `role="alert"` line on top, then focus on the first bad field | one status line |
| error summary with links | no | no | no |
| steps and progress | no, one page | no | no |
| date | native `type="date"` | native `type="date"` (`fieldConfigs.input: date`) | native `type="date"` |
| file | not offered | bare `<input type="file">` | not offered |
| review before sending | no | no | no |
| confirmation | text, reference, "keep this reference", `role="status"` | `role="status"` line | status line |
| save and resume | no (planned, see below) | no | no |

The NL Design System guidelines say: mark the fields that are not required with "(niet verplicht)", put an error summary above the form whose heading takes focus and whose items link to the fields, show progress on a multi-step form, offer a summary before sending and a confirmation page after. Source: `oip-forms/report.md` part C, with links to nldesignsystem.nl. Open Formulieren runs the same sequence: start, steps with a progress indicator, summary with edit links, confirmation.

## What changes

New, for all three renderers (shared field layer):

- Non-required fields carry "(niet verplicht)" after the label. Required fields carry no mark and get `aria-required="true"`. The form opens with one sentence that says so. The `*` goes.
- An error summary above the form on a failed submit or step. Its heading takes focus. Each item is a link that moves focus to its field. The per-field message stays under the field.
- A date field renders as a group of three inputs, Dag, Maand and Jaar, in a fieldset whose legend is the question.

New, for create and update actions (`SchemaField.vue`, the only renderer with files today):

- A styled file input: a button-like label on a real file input, a hint with the size limit, and the chosen file listed by name with a way to remove it.
- `fieldConfigs.<field>.widget`, a presentation hint the learniq absence form needs (learniq `site-guardian-portal-design`). Two values: `choices` draws a field with options as radio cards instead of a select. `dateChoices` offers a few named days ("Vandaag, vrijdag 2 oktober") plus "Een andere dag", which opens the date group. Verified on `development`: `ActionConfigNormaliser` keeps `label`, `placeholder`, `help`, `size`, `input`, `type` and `valueLabels` on a field config, and drops `widget`.

New, for published forms (`IntakeFormBlock.vue`):

- Steps. When the published form declares `steps`, the block shows one step at a time with a progress indicator, "Vorige stap" and "Volgende stap". Each step validates before the next one opens.
- A review step, "Controleren en versturen", listing every answer per step with a link back to that step.
- A confirmation page: a heading, the reference, the form's own next-steps text and how to reach the organisation. Focus moves to its heading.
- A place for "Opslaan en later verdergaan" in the step navigation. Its behaviour belongs to `intake-conditional-questions-and-drafts` (T06, T07).

## Existing work this builds on, and does not redo

- `intake-conditional-questions-and-drafts`: conditions (`visibleWhen`) on the site are PR #1071 (open). Save and resume is its T06 and T07, waiting on openregister's journey run service (`or-form-and-journey-registry` tasks 2 and 3). This change only places the button and defines where the resident lands on resume.
- `portal-intake-form` (REQ-PIFO-004): the server validates a submission against the form's schema. That stays the authority. Client-side step validation is a courtesy.
- `@conduction/nextcloud-vue` `CnFormPage` already models `steps: [{ id, title, description?, fields[] }]` and skips a step whose fields are all hidden. The site block reads the same shape. It does not mount `CnFormPage`, which is not in the `public` entry (#1071, its design D4).

## Not in this change

- File fields on published intake forms. `PortalFormValidator` and the intake submit path accept no files today. The absence mockup's attachment belongs to a create action, which already submits files.
- One question per page. No NL Design System guideline asks for it (`oip-forms/report.md`, part C).
- Authoring steps. A form's `steps` are authored in the form designer (buildiq, ADR-085 §6).
- Payment, co-signing and appointments from the Open Formulieren flow. Payment on submit is #1069.
- The Woo request form itself and the absence report action. Those are the dossiq and learniq lanes' page declarations.

## Mockups cited

`DossiqWoo.dc.html` (progress, date groups, "(niet verplicht)", step navigation, save button, side summary), `LearniqAbsence.dc.html` (error summary "Er ontbreekt nog iets", field error, styled upload, phone width).

## Affected projects

- portaliq: `src/site/components/IntakeFormBlock.vue`, `FormBlock.vue`, `c/SchemaForm.vue`, `c/SchemaField.vue`, a shared field layer under `src/site/components/forms/`, `lib/Service/Intake/PortalFormBindingResolver.php` (pass `steps`), strings.
- buildiq / openregister: none for this change. Steps already exist in the form shape.
