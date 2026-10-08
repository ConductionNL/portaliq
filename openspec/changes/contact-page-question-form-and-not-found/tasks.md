# Tasks: contact-page-question-form-and-not-found

- [ ] **T01**: `src/site/widgets/contactForm/` (meta.js, ContactForm.vue): props `app`, `action`, `subjectField`, `intro`; writes through the contribution create path; signed-out state; strings in Dutch and English (REQ-SCN-001)
- [ ] **T02**: Mail template `contact-confirmation` and the queueing after a successful `contactForm` create; PHPUnit: no question text in the body (REQ-SCN-002)
- [ ] **T03**: `lib/Settings/sites/zuiddrecht.json`: rebuild `/contact` per the Contact board and add `/contact/vraag` with the `contactForm` block (REQ-SCN-003)
- [ ] **T04**: `src/site/components/NotFoundPage.vue` per the NietGevonden board, used by the 404 branch of `src/site/App.vue`; `portal.contactRoute` optional, default `/contact` (REQ-SCN-004)
- [ ] **T05**: node tests `tests/contact-form.spec.mjs` and `tests/not-found-page.spec.mjs`; the existing unpublished-equals-missing test stays green
- [ ] **T06**: Playwright: open a missing route, follow Contact, send a question signed in
- [ ] **T07**: Ask pipelinq in its tracker for a `citizen` create action on `ticket` (ticketType question) with a subject enum, and a questions collection for residents
- [ ] **T08**: Live check against the Contact and NietGevonden boards; screenshots in the build PR
