# Test Plan: translated-message-notice

## Test Cases

### TC-1: The preference is set, cleared, refused and read back
- **spec_ref**: `openspec/changes/translated-message-notice/specs/guardian-message-translation/spec.md#requirement-a-guardian-chooses-the-language-messages-are-shown-in`
- **type**: api
- **steps**: PATCH `messageLanguage` "ar", "", "Arabic please"; GET details
- **expected result**: stored, cleared, 400; GET returns the holder's own fields
- **test command**: `vendor/bin/phpunit --filter 'PortalSelfServiceService|PortalAccountSelfController'`

### TC-2: Duck-typed client
- **spec_ref**: `openspec/changes/translated-message-notice/specs/guardian-message-translation/spec.md#requirement-messages-are-translated-through-hermiq-only-when-hermiq-is-there-and-labels-its-answer`
- **type**: functional
- **steps**: no engine class; engine throws; engine answers without `translatedByAi`; engine answers labelled
- **expected result**: null, null, null, a normalised entry with `originalRef`
- **test command**: `vendor/bin/phpunit --filter MessageTranslationClientTest`

### TC-3: Stored, reused, bounded, skipped
- **spec_ref**: `openspec/changes/translated-message-notice/specs/guardian-message-translation/spec.md#requirement-translation-work-per-request-is-bounded-and-skips-what-needs-none`
- **type**: functional
- **steps**: five untranslated staff messages; one cached; one from the reader; one detected in the reader's language
- **expected result**: three calls; cached reused; own skipped; same-language stored but not attached; `body` unchanged on save
- **test command**: `vendor/bin/phpunit --filter GuardianMessageTranslatorTest`

### TC-4: The endpoint attaches translations only with a preference
- **spec_ref**: `openspec/changes/translated-message-notice/specs/guardian-message-translation/spec.md#requirement-the-stored-message-keeps-both-texts-and-the-provenance`
- **type**: api
- **steps**: read a thread with and without `messageLanguage`
- **expected result**: translator called only with a preference
- **test command**: `vendor/bin/phpunit --filter MessageGuardianControllerTest`

### TC-5: The notice renders as a landmark with the original one click away
- **spec_ref**: `openspec/changes/translated-message-notice/specs/guardian-message-translation/spec.md#requirement-the-reader-sees-a-notice-that-ai-translated-the-text-with-the-original-one-step-away`
- **type**: accessibility
- **steps**: render `TranslatedText` closed and open; render a row without translation; render the inbox with a translated row
- **expected result**: `aside` with aria-label, notice text, `lang` on both texts, `aria-expanded` toggles, no notice without translation
- **test command**: `node --test tests/translated-message-notice.spec.mjs`

## Coverage Summary

All five requirements are covered by at least one case.

## Out of Scope

A live run against hermiq with an enabled feature needs an instance where a DPO enabled `message-translation`; it is left for the landing.
