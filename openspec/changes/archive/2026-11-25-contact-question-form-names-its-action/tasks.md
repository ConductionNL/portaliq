# Tasks: contact-question-form-names-its-action

- [x] **T1**: `lib/Settings/sites/zuiddrecht.json` page `/contact/vraag` binds to pipelinq `askQuestion`
  - `tests/contact-form.spec.mjs` "the example contact page binds to the question action pipelinq contributes"
- [x] **T2**: Live check on the Zuiddrecht rehearsal: the form shows, and a sent question is listed under Mijn vragen
  - Done 25 Nov 2026 on a sandbox Nextcloud 34.0.6 (MariaDB) with pipelinq, dossiq, opencatalogi, openregister and portaliq development and the Zuiddrecht example set, as Sanne (demo card): `/contact/vraag` shows "Onderwerp", "Uw vraag" and "Vraag versturen"; a sent question answers 200 through pipelinq's `askQuestion`, the block says "Wij hebben uw vraag ontvangen", and "Mijn vragen" (`/mijn/pipelinq/questions`) lists it with status Ontvangen. Fixed on the way: "Naar Mijn vragen" was a bare `/mijn`, which leaves the site under the app path (`ContactForm.questionsHref` now writes the site's own address, test in `tests/contact-form.spec.mjs`), and the example page now points it at the Mijn vragen page instead of the overview.
  - Not archived yet: REQ-SCN-003, which this change modifies, still lives in the open change `contact-page-question-form-and-not-found`; archive this one after that one.
