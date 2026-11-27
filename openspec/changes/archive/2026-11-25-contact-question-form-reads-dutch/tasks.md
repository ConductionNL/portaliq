# Tasks: contact-question-form-reads-dutch

- [x] 1.1 The contact block hands the schema form a translator in the page's language
  - Files: src/site/components/ContactForm.vue
  - Test: tests/contact-form.spec.mjs
- [x] 1.2 The optional mark keeps its space
  - Files: src/site/components/forms/LabelSuffix.vue
  - Test: tests/contact-form.spec.mjs
- [x] 1.3 Live: open /contact/vraag in Dutch
  - Done 25 Nov 2026 (live-portaliq-1) on a sandbox Nextcloud 34.0.6 + MariaDB with OpenRegister main, opencatalogi, dossiq, pipelinq and portaliq development and the Zuiddrecht example set, as Sanne de Vries (`sanne.devries`, nl-NL browser): the form reads "Stel een vraag", "Noem het onderwerp en stel uw vraag. U vindt hem terug bij Mijn vragen.", "Onderwerp" (placeholder "Waar gaat uw vraag over?"), "Uw vraag" and "Vraag versturen", and after sending "Wij hebben uw vraag ontvangen. U vindt hem terug bij Mijn vragen." Both fields of pipelinq's `askQuestion` are required, so no "niet verplicht" sentence shows, as specified for a form without an optional field; the optional mark itself stays covered by `tests/contact-form.spec.mjs`.
