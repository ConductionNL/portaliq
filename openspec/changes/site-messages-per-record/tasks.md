# Tasks: site-messages-per-record

- [x] **T1**: `MessageContactsKeys` (collection key `contacts`, provider answer shape), called from `CollectionConfigNormaliser`
  - PHPUnit `MessageContactsKeysTest` (through the real `PortalManifestNormaliser`)
- [x] **T2**: `MessageContactReader` (`contactsFor`, `contactFor` proven through the scoped reader); `GET /api/messages/contacts`; `POST /api/messages/threads` with `recordRef`, `title`, `body`; `createContactThread` on the messaging leaf; `summary` on the thread list; messageThread 0.2.0, register 0.68.0
  - PHPUnit `MessageContactReaderTest` (7: per-row contacts, trust, proof, refusal, stored contact, 400s, summary), `InAppMessagingLeafTest` (2 new)
- [x] **T3**: the site: `messaging()` on the portal API; `conversations.js`; `MessagesPage.vue` with tabs, cards, reply and the form
  - node `tests/site-messages-per-record.spec.mjs` (`check:site-messages-per-record`, in `check:specs`); `tests/site-inbox-pages.spec.mjs` updated (cards instead of action rows; a thread opens on its button)
- [ ] **T4**: live check next to the Wilgenboom and Vaartveld `Berichten` boards, once learniq declares `contacts` (learniq `portal-message-contacts`)
- [ ] **T5** (follow-up): a staff screen for these threads (today staff reply through `/api/staff/messages/*` only)
- [x] **T6**: `openspec validate site-messages-per-record --strict`
