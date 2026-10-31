# Proposal: contact-question-form-reads-dutch

## Why

Rehearsal R3, 16 October: the question form on `/contact/vraag` said "A field without "optional" must be filled in." and labelled the question "Uw vraag(optional)", while the dossier question form says "niet verplicht". Two causes:

1. The contact block rendered the schema form without a translator, so the form fell back to its English keys. The Dutch words were in the schema form's own strings all along.
2. The optional mark lost its space: Vue drops a whitespace-only text node at the start of an element, and the mark's space was one. Every form showed "Naam(niet verplicht)".

## What changes

- The contact block hands the schema form a translator over the schema form's strings, in the page's language.
- The optional mark carries its space inside the interpolation, so every form reads "Uw vraag (niet verplicht)".

## Not changed

- Which fields are required: that is the action's declaration (pipelinq `askQuestion`).

## Rollback

Revert the commit.
