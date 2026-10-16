# Proposal: a-create-stamps-the-moment-it-was-made

## Why

Rehearsal R3, 16 October: "Gesteld" on Mijn vragen was empty for a question asked on `/contact/vraag`. That form is pipelinq's `askQuestion` create action, which portaliq writes. Pipelinq's own question endpoint stamps `occurredAt` on the ticket it writes; a create action had no way to ask for that, and a static `defaults` value cannot carry "now".

## What changes

- A create action MAY name `createdAtField`. Portaliq stamps that field with the moment of the create (ISO 8601, UTC), over anything the client sent, on the signed-in and the anonymous create path and on a saved draft.
- A name that is not a plain property name stamps nothing.
- Pipelinq's `askQuestion` declares `createdAtField: occurredAt` (pipelinq `a-contact-question-is-required-and-dated`).

## Rollback

Revert the commit; an action that declares the key then stamps nothing.
