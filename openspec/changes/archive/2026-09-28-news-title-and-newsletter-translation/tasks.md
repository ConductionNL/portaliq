# Tasks: news-title-and-newsletter-translation

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 4. -->

Every task starts with a test that fails first.

### Task 1: The translator puts the title in the body's entry
- **files**: `lib/Service/Messaging/GuardianMessageTranslator.php`, `tests/Unit/Service/NewsFeedReaderTest.php`
- [x] Implement and test

### Task 2: The archive carries its items, translated and gated like the feed
- **files**: `lib/Service/NewsFeedReader.php`, `lib/Controller/NewsGuardianController.php`, `tests/Unit/Service/NewsFeedReaderTest.php`, `tests/Unit/Controller/NewsGuardianControllerTest.php`
- [x] Implement and test

### Task 3: newsItem 0.2.1, register 0.37.1, demo title
- **files**: `lib/Settings/portaliq_register.json`, `lib/Settings/portaliq_mock_register.json`, `tests/Unit/Settings/PortaliqRegisterConfigTest.php`, `tests/news-item-translation.spec.mjs`
- [x] Implement and test

### Task 4: The News page shows the translated title and the archive
- **files**: `src/portal/components/NewsPage.jsx`, `TranslatedText.jsx`, `lib/portalApi.js`, i18n, `theme.css`, `package.json`, `tests/news-title-and-newsletter-translation.spec.mjs`
- [x] Implement and test
