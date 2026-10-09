## ADDED Requirements

### Requirement: An action may sum up the answers in one sentence

A create action, or an endpoint action with `fields`, MAY declare `answerSummary` with a `template` of at most 300 characters, an optional `label` and optional `phrases` (`{field: {answer: words}}`). Every `{field}` in the template MUST be one of the action's own fields; otherwise the summary MUST be dropped whole. A template that names no field MUST be dropped. The form MUST show the sentence, with its label, above the send button on a one-page form and on the review step of a stepped form, once every answer the template names is given, in a polite live region. Each placeholder MUST read as the phrase for that answer, else the label of the chosen option, else, for a date answer (`yyyy-mm-dd`), "vandaag", "morgen" or "gisteren" or the weekday, day and month in the page language (with the year only when it is not this year), else the answer as given, and the sentence MUST be rendered as text. After sending, the confirmation MUST show the sentence as it stood when sent. The key `summary` is the start tile's plain sentence (decision 127, site-nlds-widget-palette REQ-SNW-020); an object an app still declares under `summary` MUST be read as `answerSummary`, and a stored contribution that holds it MUST have it moved to `answerSummary` on upgrade.

#### Scenario: Reporting a child absent
- GIVEN an absence action with `answerSummary: {label: "U meldt", template: "{learner} is {when} {reason}.", phrases: {when: {today: "vandaag de hele dag"}, reason: {sick: "ziek"}}}`
- WHEN a parent picks Sami, today and sick
- THEN the form shows "U MELDT" and "Sami is vandaag de hele dag ziek." above the send button
- AND the confirmation shows the same sentence

#### Scenario: An answer is still missing
- GIVEN the same action and only Sami and today picked
- WHEN the form renders
- THEN no sentence shows

#### Scenario: A placeholder for a field the action does not send
- GIVEN a summary whose template names `{bsn}`, which is not one of the action's fields
- WHEN the contribution is normalised
- THEN the action carries no summary

#### Scenario: A date answer
- GIVEN a summary with `{when}` and no phrase for it, on Monday 5 October 2026
- WHEN the parent picks Monday 12 October in the date choices
- THEN `{when}` reads "maandag 12 oktober", and picking today reads "vandaag"

#### Scenario: The old object shape under summary
- GIVEN an app that still declares the sentence object under `summary`
- WHEN the contribution is normalised, or a stored contribution is upgraded
- THEN the action carries the sentence under `answerSummary` and no object under `summary`
