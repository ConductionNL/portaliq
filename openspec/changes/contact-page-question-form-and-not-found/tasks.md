# Tasks: contact-page-question-form-and-not-found

- [x] **T01**: `src/site/widgets/contactForm/` (meta.js, ContactForm.vue): props `app`, `action`, `topicField`, `intro`; writes through the contribution create path; signed-out state; strings in Dutch and English (REQ-SCN-001)
- [x] **T02**: Mail template `contact-confirmation` and the queueing after a successful `contactForm` create; PHPUnit: no question text in the body (REQ-SCN-002)
- [x] **T03**: `lib/Settings/sites/zuiddrecht.json`: rebuild `/contact` per the Contact board and add `/contact/vraag` with the `contactForm` block (REQ-SCN-003)
- [x] **T04**: `src/site/components/NotFoundPage.vue` per the NietGevonden board, used by the 404 branch of `src/site/App.vue`; `portal.contactRoute` optional, default `/contact` (REQ-SCN-004)
- [x] **T05**: node tests `tests/contact-form.spec.mjs` and `tests/not-found-page.spec.mjs`; the existing unpublished-equals-missing test stays green
- [ ] **T06**: Playwright: open a missing route, follow Contact, send a question signed in — not run: needs a live instance
- [ ] **T07**: Ask pipelinq in its tracker for a `citizen` create action on `ticket` (ticketType question) with a subject enum, and a questions collection for residents — not run: needs pipelinq
- [ ] **T08**: Live check against the Contact and NietGevonden boards; screenshots in the build PR — not run: needs a live instance

## Build notes

- `contactForm` is a site block registered like `intakeForm` (`WidgetGrid.vue`, `pageWidgetCatalogue.js`), with its component at `src/site/components/ContactForm.vue`. It finds its create action among the resident's contributions and shows nothing it could not find.
- The confirmation mail is a follow-on in `ContributionController::create`, asked for by `confirmationMail: "contact-confirmation"` on the action. The "Bevestiging contactformulier" entry on the mail templates screen belongs to mail-templates-admin-screen.
- The contact page uses `nlLinkList` cards for the channels, because the palette has no card widget.
