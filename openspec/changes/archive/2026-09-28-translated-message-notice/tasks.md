# Tasks: translated-message-notice

## Implementation Tasks

### Task 1: Register fields and versions
- **spec_ref**: `openspec/changes/translated-message-notice/specs/guardian-message-translation/spec.md#requirement-the-stored-message-keeps-both-texts-and-the-provenance`
- **files**: `lib/Settings/portaliq_register.json`, `lib/Settings/portaliq_mock_register.json`, `l10n/en.json`, `l10n/nl.json`, `tests/Unit/Settings/PortaliqRegisterConfigTest.php`
- **acceptance_criteria**:
  - GIVEN the register WHEN read THEN info and registers.portaliq are 0.36.0, portalAccount 0.10.0 has messageLanguage, guardianMessage 0.2.0 has translations, every new string has a catalogue key, demo rows carry the fields
- [x] Implement
- [x] Test

### Task 2: The language preference
- **spec_ref**: `openspec/changes/translated-message-notice/specs/guardian-message-translation/spec.md#requirement-a-guardian-chooses-the-language-messages-are-shown-in`
- **files**: `lib/Service/Identity/PortalSelfServiceService.php`, `lib/Controller/PortalAccountSelfController.php`, `appinfo/routes.php`, tests
- **acceptance_criteria**:
  - GIVEN the holder WHEN PATCH messageLanguage "ar" / "" / "Arabic please" THEN stored / cleared / 400
  - GIVEN the holder WHEN GET details THEN own fields only
- [x] Implement
- [x] Test

### Task 3: The duck-typed hermiq client
- **spec_ref**: `openspec/changes/translated-message-notice/specs/guardian-message-translation/spec.md#requirement-messages-are-translated-through-hermiq-only-when-hermiq-is-there-and-labels-its-answer`
- **files**: `lib/Service/Messaging/MessageTranslationClient.php`, `tests/Unit/Service/Messaging/MessageTranslationClientTest.php`
- **acceptance_criteria**:
  - GIVEN no hermiq, a throwing engine or an unlabelled answer THEN null; GIVEN a labelled answer THEN a normalised entry
- [x] Implement
- [x] Test

### Task 4: The translator and the endpoint
- **spec_ref**: `openspec/changes/translated-message-notice/specs/guardian-message-translation/spec.md#requirement-translation-work-per-request-is-bounded-and-skips-what-needs-none`
- **files**: `lib/Service/Messaging/GuardianMessageTranslator.php`, `lib/Controller/MessageGuardianController.php`, tests
- **acceptance_criteria**:
  - GIVEN five untranslated staff messages THEN three calls; cached entries reused; the reader's own messages skipped; same-language results not attached; body kept
  - GIVEN no preference THEN the translator is not called
- [x] Implement
- [x] Test

### Task 5: The notice, the Messages page and the inbox
- **spec_ref**: `openspec/changes/translated-message-notice/specs/guardian-message-translation/spec.md#requirement-the-reader-sees-a-notice-that-ai-translated-the-text-with-the-original-one-step-away`
- **files**: `src/portal/components/TranslatedText.jsx`, `src/portal/components/MessagesPage.jsx`, `src/portal/components/InboxPage.jsx`, `src/portal/App.jsx`, `src/portal/lib/portalApi.js`, `src/portal/i18n/en.json`, `src/portal/i18n/nl.json`, `src/portal/theme.css`, `tests/translated-message-notice.spec.mjs`, `package.json`
- **acceptance_criteria**:
  - GIVEN a translated message THEN an aside landmark reads "Translated by AI from Dutch", the button toggles the original with aria-expanded, both texts carry lang
  - GIVEN no translation THEN no notice
- [x] Implement
- [x] Test

## Quality checklist

- PHPUnit tests for every new service and changed controller
- node --test spec for the SPA components (the portal's own test style)
- en and nl strings in the portal SPA bundles and the schema catalogue
- `openspec validate translated-message-notice` passes
