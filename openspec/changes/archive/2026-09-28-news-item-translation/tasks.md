# Tasks: news-item-translation

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 5. -->

### Task 1: newsItem declares translations (register 0.37.0, newsItem 0.2.0, demo row)
- **files**: `lib/Settings/portaliq_register.json`, `lib/Settings/portaliq_mock_register.json`, `tests/Unit/Settings/PortaliqRegisterConfigTest.php`
- [x] Implement and test

### Task 2: The translator writes to a given schema
- **files**: `lib/Service/Messaging/GuardianMessageTranslator.php`, `tests/Unit/Service/Messaging/GuardianMessageTranslatorTest.php`
- [x] Implement and test

### Task 3: The feed translates the stored rows before the photo gate
- **files**: `lib/Service/NewsFeedReader.php`, `lib/Controller/NewsGuardianController.php`, tests
- [x] Implement and test

### Task 4: The news page with the AI notice
- **files**: `src/portal/components/NewsPage.jsx`, `MessagesPage.jsx`, `App.jsx`, `lib/portalApi.js`, i18n, `tests/news-item-translation.spec.mjs`
- [x] Implement and test

### Task 5: Docs
- **files**: `docs/operations/translated-messages.md`
- [x] Update
