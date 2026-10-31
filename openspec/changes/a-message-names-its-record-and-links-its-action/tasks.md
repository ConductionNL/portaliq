# Tasks: a-message-names-its-record-and-links-its-action

- [x] 1. Normaliser: `senderRoleField`, `aboutField`, `aboutLinkField`, `actionField` on an inbox collection; `tabs`, `tabField` on the inbox page.
  - PHPUnit for the normaliser (`InboxMessageFieldsTest`)
- [x] 2. `InboxPage.vue` / `InboxBlock.vue`: role line, about line, the action button (same-portal addresses only), tabs with counts, mark all read.
  - `node --test tests/site-inbox-fields.spec.mjs`
- [ ] 3. Reply below an opened notice when the collection declares a reply action. — not run: the reply form and its write endpoint belong to the conversation pattern on the messages page; the inbox has no reply action binding to call yet.
- [x] 4. i18n en and nl.
- [ ] 5. learniq projects the fields on its notices (`bpv-hours-per-day`, `pupil-books-support-lessons-and-mentor-talks`, academy enrolment notices). — not run: needs learniq
