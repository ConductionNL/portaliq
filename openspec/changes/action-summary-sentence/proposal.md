# Proposal: action-summary-sentence

## Why

The primary-school portal's absence form (board MobielDetail, De Wilgenboom, 5 October 2026; plan gap G-27) ends with a short check before the parent sends: "U meldt: Sami is vandaag de hele dag ziek." A parent who picked three answers on a phone sees, in one sentence, what the school will receive. Today a stepped form has a review list (`ReviewList`), but a one-page form has nothing, and neither reads as a sentence.

## What changes

- A create action, or an endpoint action with fields, MAY declare `summary: { label?, template, phrases? }`. `template` names the action's own fields as `{field}`; `phrases` gives the words for a stored answer per field.
- The server keeps it only when every placeholder is one of the action's fields (`ActionSummaryNormaliser`, called from `FormStepsNormaliser` like `confirmation`), and an attached action lists it.
- The form shows the sentence above the send button (on a one-page form, or on the review of a stepped one) as soon as every answer it names is given, in a polite live region, and again on the confirmation, as it stood when sent.

## Not in this change

- Saving a form and carrying on later (`site-multi-step-forms` T8) is open in PR #1152; this change does not touch drafts.
- A placeholder for a property of the chosen option other than its label (the board's "De melding gaat naar juf Esra" names the child's teacher). The contributing app can project that into the option label or leave it out.
