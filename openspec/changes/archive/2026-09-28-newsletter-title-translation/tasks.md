# Tasks: newsletter-title-translation

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 3. -->

Every task starts with a test that fails first.

### Task 1: The archive translates the newsletter's own title within the shared bound
- **files**: `lib/Service/Messaging/GuardianMessageTranslator.php`, `lib/Service/NewsFeedReader.php`, `tests/Unit/Service/NewsFeedReaderTest.php`
- [x] Implement and test

### Task 2: newsletter 0.2.0, register 0.38.0, demo entry, catalogue key
- **files**: `lib/Settings/portaliq_register.json`, `lib/Settings/portaliq_mock_register.json`, `tests/Unit/Settings/PortaliqRegisterConfigTest.php`, `l10n/`
- [x] Implement and test

### Task 3: The newsletters section shows the translated title under the notice
- **files**: `src/portal/components/NewsPage.jsx`, `src/portal/components/TranslatedText.jsx`, `package.json`, `tests/newsletter-title-translation.spec.mjs`
- [x] Implement and test
