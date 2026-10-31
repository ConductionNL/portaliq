# Proposal: contact-question-form-names-its-action

## Why

Seen in the Zuiddrecht go-live rehearsal: `/contact/vraag` said "U kunt nu geen vraag stellen". The example page bound its `contactForm` block to a pipelinq action `ask-question` that pipelinq never contributed, so the block correctly found nothing to post to.

## What changes

- pipelinq contributes `askQuestion` (pipelinq change `a-resident-asks-a-question-without-a-dossier`): a create action that files a question listed under Mijn vragen, with Onderwerp and Uw vraag and the `contact-confirmation` mail naming the subject.
- The example page binds to `askQuestion`. Its unused `topicField` prop is dropped: the topic the mail names is the action's own `topicField`, which the server keeps.

## Not changed

- `ContactForm.vue`: it already refuses to post to an action it cannot find.
- The Onderwerp is the action's text field; a choice list of subjects stays a later option for a contribution that offers one.
