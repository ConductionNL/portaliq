# Design: site-multi-step-forms

## Context

Three renderers draw forms on the site, each with its own markup (proposal, table). They share the Utrecht field CSS already in the entry (`form-field`, `form-label`, `form-field-description`, `form-field-error-message`, `textbox`, `textarea`, `select`). The site bundle budget is 412 KiB (`webpack.site.js`), and the entry sat about 1.3 KiB under it at the last bump. All three form blocks load on demand (`defineAsyncComponent` in `WidgetGrid.vue`, the `action` block slot for `SchemaForm`).

## D1. One shared field layer, loaded with the forms

New components under `src/site/components/forms/`:

| component | NL Design System name | CSS |
|---|---|---|
| `FieldShell.vue` | Form Field, Form Field Label, Form Field Description, Form Field Error Message | Utrecht, in the entry today |
| `LabelSuffix.vue` | Form Field Label Suffix | none upstream (Help Wanted); own, on `--utrecht-form-label-*` and `--utrecht-document-color` tokens |
| `ErrorSummary.vue` | Form Summary pattern for errors | `@utrecht/alert-css` look, own layout |
| `DateInputGroup.vue` | Date Input Group | none upstream (Help Wanted); own, three `utrecht-textbox` inputs in a `utrecht-form-fieldset` |
| `FileUpload.vue` | File Input | none upstream; own, `utrecht-button--secondary-action` look on a label |
| `ChoiceCards.vue` | Radio Group | none upstream (Help Wanted); `@utrecht/radio-button-css` inside own cards |
| `FormProgress.vue` | Progress List, Form Navigation | none in Utrecht; Den Haag `denhaag-form-progress` exists in `@gemeente-denhaag/components-css` and is used when `site-mijn-omgeving-components` loads that CSS, else own |
| `ReviewList.vue` | Form Summary, Description List | `@utrecht/data-list-css` look via `site-mijn-omgeving-components`' description list |

The three renderers import them. Nothing here is imported by the entry, so the components travel in the form chunks. New CSS goes in the components' `<style>` blocks, which `style-loader` puts in the same chunk. `npm run build:site` must stay within the budget. That is the gate.

Not `CnFormPage`: it is not in `@conduction/nextcloud-vue/public`, and it reaches `@nextcloud/*` runtime (PR #1071, design D4).

## D2. Required and optional

NL Design System: mark the non-required field, not the required one. Use "(niet verplicht)". Explain it above the form. Use `aria-required="true"`.

- `LabelSuffix` renders `(niet verplicht)` (en: `(optional)`) inside the `<label>` or `<legend>`, so it is read with the name.
- A required field gets `aria-required="true"` and keeps the native `required` off. The browser's own bubble then does not compete with the summary. Each renderer already uses `novalidate` or its own submit handler.
- Above the first field: `Een veld zonder "niet verplicht" moet u invullen.` (from `DossiqWoo.dc.html` and `LearniqAbsence.dc.html`). Left out when every field is required, or none is.

## D3. Error summary

- Shown after a failed submit or a failed "Volgende stap", above the fields, below the form heading.
- Heading "Er ontbreekt nog iets" with a one-line instruction (`LearniqAbsence.dc.html`). It gets `tabindex="-1"` and focus. That replaces `SchemaForm.focusFirstError()`, which moved focus to the field and skipped the overview.
- One link per error, in field order. The link text is the field's message. Activating it focuses the field, or the first input of a group.
- Server errors (`IntakeFormBlock` `outcome.errors`, `SchemaForm` errors map) feed the same list. On a multi-step form a server error on an earlier step moves the resident to that step first.
- The per-field message stays under its field, linked by `aria-describedby` as today.
- The document title gets a prefix `Fout: ` while errors stand, so a screen reader user hears it on focus change. Removed on the next successful step.

## D4. Date as day, month, year

- A field whose input is `date` (intake `type: date`, action `fieldConfigs.input: date`) renders `DateInputGroup`: a fieldset, the question as legend, the hint ("Bijvoorbeeld 1 3 2026"), and three inputs labelled Dag, Maand, Jaar with `inputmode="numeric"`.
- The value stays the ISO string the server already expects (`yyyy-mm-dd`). An incomplete or impossible date is an error with a specific message ("Vul een geldige datum in, bijvoorbeeld 1 3 2026").
- `datetime` stays the native input. NL Design System has no pattern for it yet.

## D5. Choice cards and named days (`fieldConfigs.widget`)

- `ActionConfigNormaliser` keeps `widget` when it is `choices` or `dateChoices`, and drops any other value (fail to the default input, as `size` does).
- `choices` on a field with options draws `ChoiceCards`: a fieldset with one radio per option, each in a card with its label. Same value as the select it replaces.
- `dateChoices` on a date field draws today and the next school or working days the action names in `fieldConfigs.<field>.dateChoices` (a count, 1 to 5, default 2), plus "Een andere dag". The last opens `DateInputGroup`. Day names come from the site's locale.
- `choices` may narrow the cards to `choiceOptions` plus an "other" card (`otherLabel`) that reveals the remaining options in a select. Learniq's absence kinds: six options, three cards in `LearniqAbsence.dc.html`.
- Presentation only. The value and its validation are unchanged.

## D6. Steps on a published form and on a create action

- A create action, or an endpoint action with `fields` (the dossiq Woo actions are endpoint actions), MAY declare `steps` in the same shape. `draft` and `confirmation` likewise.
- `required` on an action that names no `schema` is dropped today (`ActionConfigNormaliser::applyFieldFlags()`: no schema, empty mandatory set). That stays; every field of such an action reads "(niet verplicht)" (REQ-SMF-023).
- A create action MAY declare `steps` in the same shape. `ActionConfigNormaliser` keeps a step whose `fields` are all in the action's `fields`, drops the rest, and puts loose fields in a last step. A step with `review: true` is the review step (D7) and carries no fields. `SchemaForm.vue` renders the steps as `IntakeFormBlock.vue` does.
- `PortalFormBindingResolver` passes the form's `steps` (`[{ id, title, description?, fields[] }]`, the shape `CnFormPage` reads) through in the form render, after keeping only steps whose `fields` name known fields. A field in no step goes in a last step of its own. Without `steps` the block renders as today, one page, no progress.
- The block shows one step at a time. `FormProgress` lists every step with its state: done, current (`aria-current="step"`), to do. On a phone it collapses to "Stap 2 van 4" with the list behind a button.
- "Volgende stap" validates the step's visible fields (required, the date group) before moving. "Vorige stap" never validates.
- A step whose fields are all hidden by `visibleWhen` is skipped both ways, as `CnFormPage` does (#1071 evaluates the conditions).
- Moving to a step moves focus to the step heading ("Stap 2 van 4: periode en documenten") and updates the document title.
- Steps are client-side. The server receives one submission, as today, and validates it whole (REQ-PIFO-004).

## D7. Review and confirmation

- A form with steps ends with "Controleren en versturen": `ReviewList` per step, each answer under its question, a "Wijzigen" link that opens the step and returns to the review after "Volgende stap". Hidden answers are not listed and not sent.
- The side card "Uw antwoorden tot nu toe" in `DossiqWoo.dc.html` is the same list, limited to done steps. It is optional: a block setting `showProgressSummary`.
- After a successful submit, the confirmation replaces the form: heading "Wij hebben uw aanvraag ontvangen" (or the form's name), the reference, the form's `confirmationText` as next steps, and a contact line from the portal's footer contact when present. Focus moves to the heading. `role="status"` stays for the reference line.

## D8. Save and resume

Two stores, one button.

- A published form: owned by `intake-conditional-questions-and-drafts` (REQ-ICQ-005, tasks T06 and T07, blocked on openregister journey runs).
- A create action with `draft: { retentionDays }` (1 to 90): a new `portalDraft` schema in portaliq's register. One object per signed-in subject, contribution and action: the visible answers, the step reached, `expiresAt`. Read and written only through portaliq's own routes, scoped to the subject, never forwarded to the app. Deleted on a successful send. A background job deletes expired drafts. A signed-out visitor gets no draft. File answers are not kept in a draft; the step with the file asks again.
- Ruben may later fold both into journey runs once openregister ships them. Listed as a decision.

The button and the landing step are the same for both:

- The button "Opslaan en later verdergaan" sits in the step navigation, after "Volgende stap", styled subtle (`DossiqWoo.dc.html`).
- A resumed draft opens on the first step that has a missing required answer, else on the review.

The retention sentence ("Wij bewaren uw antwoorden 30 dagen. U kunt later verdergaan.") reads the declared `retentionDays` or the run's own date, never a number in the renderer.

## D9. Confirmation from the action

A create action MAY declare `confirmation: { title, body?, next? }`. `{identifier}` and `{deadline}` are filled from the action's answer (dossiq's `start()` returns `identifier` and `deadline`). A sentence whose placeholder has no value is left out. Without `confirmation` the action keeps `successMessage`, as today.

## Risks

- Budget. Six small components in the form chunks. They are not in the entry. If a chunk grows past `maxAssetSize` the build fails, which is the check.
- Translation. Every new string needs nl, en and en_US entries (`check:l10n-js`).
- The NL Design System form guidance was read through a summarising fetch (`oip-forms/report.md`). The build PR re-reads the exact wording of the error summary and optional-field pages.
