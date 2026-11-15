---
title: Conditional questions in public forms
sidebar_label: Conditional questions
---

# Conditional questions in public forms

A request form can show a question only when it applies. "Name of your partner" appears when the resident answers "Yes" to "Do you live together?", and stays away when they answer "No".

You write the condition on the field in the form designer, as the field's `visibleWhen`. The portal reads it on the screen and checks it again when the form is sent.

## What the portal does with a hidden question

- The resident does not see it.
- A hidden required question does not stop the resident from sending the form.
- An answer to a hidden question never reaches the case. The portal drops it on the server, even when someone sends it on purpose.

## Conditions a public form supports

A condition compares one answer with a value. These work:

| Condition | Shows the question when |
|---|---|
| `{"field": "together", "op": "eq", "value": "Yes"}` | the answer is "Yes" |
| `neq` | the answer is anything else |
| `gt`, `gte`, `lt`, `lte` | the answer, read as a number, is larger or smaller |
| `empty`, `notEmpty` | the question was left open, or was answered |
| `{"all": [...]}`, `{"any": [...]}` | every condition holds, or at least one does |
| `"value": "@object.startDate"` | the answer compares with another answer on the same form |

`field` may point into a group of answers with a dot, such as `address.country`.

A question that hangs on a hidden question is hidden too.

## Conditions the portal refuses

The portal cannot repeat some conditions when the form is sent. A form that uses one of them opens no form at all:

- a condition that asks a web address (`endpoint`) or another register (`source`);
- a condition that compares with today's date (`@today`, `@now`, `@today+7d` and the like);
- a condition on an installed app (`appInstalled`).

The resident then reads that the form is not available. Under **Form bindings**, the binding's "Which form?" action tells you: "This form uses a condition the portal cannot check. Change it to a condition on another answer."

## Next step

After you change a condition, open **Form bindings** and press "Which form?" on the binding. It should name your form again.
