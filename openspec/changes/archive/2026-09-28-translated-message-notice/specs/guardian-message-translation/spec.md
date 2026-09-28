# guardian-message-translation Specification

**Status**: in-progress
**Scope**: portaliq
**OpenSpec changes**:
- `translated-message-notice`

## Purpose

A guardian reads school messages in the language they picked, and always sees that AI made the translation, from which language, with the original one click away (decision D24).

## ADDED Requirements

### Requirement: A guardian chooses the language messages are shown in

The portal account SHALL carry `messageLanguage`, a BCP-47 tag, empty by default. Only the account holder SHALL set it, through `PATCH /portal/api/identity/details` with `messageLanguage`; an empty string SHALL switch translation off; a value that is not a BCP-47 shaped tag SHALL be refused with 400. `GET /portal/api/identity/details` SHALL return the holder's own `displayName`, `email`, `emailNotifications` and `messageLanguage`, and nothing about any other account. The entity is a schema.org `Person` with `knowsLanguage`.

#### Scenario: A guardian picks Arabic

- GIVEN an authenticated portal subject with an account
- WHEN they send `PATCH /portal/api/identity/details` with `messageLanguage: "ar"`
- THEN the account's `messageLanguage` MUST be `"ar"`
- AND `GET /portal/api/identity/details` MUST return `messageLanguage: "ar"`

#### Scenario: A guardian switches translation off

- GIVEN an account with `messageLanguage: "ar"`
- WHEN the holder sends `messageLanguage: ""`
- THEN the account's `messageLanguage` MUST be empty

#### Scenario: A value that is not a language is refused

- WHEN the holder sends `messageLanguage: "Arabic please"`
- THEN the response MUST be 400
- AND the account MUST NOT change

### Requirement: Messages are translated through hermiq only when hermiq is there and labels its answer

portaliq SHALL call `OCA\Hermiq\Service\MessageTranslationEngine::translate()` only when that class exists, resolved by name at runtime, never through a hard dependency. It SHALL pass the message body, the reader's language and `originalRef: "portaliq:guardianMessage:<id>"`. It SHALL keep an answer only when `available` is true and `translatedByAi` is `true`; any other answer, a missing class or a thrown error SHALL yield no translation, and the message SHALL be shown as written.

#### Scenario: No hermiq means no translation and no error

- GIVEN hermiq is not installed
- WHEN a guardian with `messageLanguage: "ar"` reads a thread
- THEN every message MUST be returned as written, without `translation`

#### Scenario: An unlabelled answer is dropped

- GIVEN hermiq answers `available: true` without `translatedByAi`
- WHEN a guardian reads a thread
- THEN no translation MUST be stored or returned

### Requirement: The stored message keeps both texts and the provenance

A translated `guardianMessage` SHALL keep `body` as written and SHALL hold one `translations` entry per target language with `targetLanguage`, `text`, `translatedByAi`, `sourceLanguage`, `sourceLanguageDetected`, `model`, `originalRef`, `disclosure`, `disclosureLanguage` and `translatedAt`. `translations` SHALL be server-managed. The entity is a schema.org `Message`; each entry is a `CreativeWork` with `translationOfWork` and `inLanguage`.

#### Scenario: A translation is stored next to the original

- GIVEN hermiq is enabled and labels its answers
- WHEN a guardian with `messageLanguage: "tr"` reads a thread holding an untranslated Dutch message
- THEN the stored message MUST keep its Dutch `body`
- AND MUST gain a `translations` entry with `targetLanguage: "tr"`, the Turkish `text` and `sourceLanguage: "nl"`

#### Scenario: A stored translation is reused

- GIVEN a message already holds a `tr` entry
- WHEN a guardian with `messageLanguage: "tr"` reads the thread again
- THEN hermiq MUST NOT be called for that message

### Requirement: Translation work per request is bounded and skips what needs none

The translator SHALL ask hermiq for at most three new translations per request, SHALL NOT translate a message the reader sent, and SHALL NOT show a translation whose source language equals the reader's language (primary subtag). Messages over the budget SHALL be returned as written and translated on a later request.

#### Scenario: A long untranslated thread is translated three at a time

- GIVEN a thread with five untranslated messages from staff
- WHEN a guardian with `messageLanguage: "ar"` reads it
- THEN hermiq MUST be called three times
- AND two messages MUST be returned without `translation`

#### Scenario: The reader's own message is not translated

- GIVEN a thread holding a message the reader sent
- WHEN the reader reads the thread
- THEN hermiq MUST NOT be called for that message

### Requirement: The reader sees a notice that AI translated the text, with the original one step away

Wherever the portal shows a message carrying a `translation`, it SHALL show the translated text marked with its language, followed by a notice reading "Translated by AI from <language>" (or "Translated by AI" when the source language is `und`), where `<language>` is the source language's name in the portal's language. The notice SHALL be an `aside` landmark with an accessible name, SHALL carry a visible mark and text, not colour alone, and SHALL hold a button that shows and hides the original text, with `aria-expanded` and `aria-controls`. The original SHALL be marked with its language.

#### Scenario: A guardian sees the Arabic translation and the notice

- GIVEN a message with a `translation` from `nl` into `ar`
- WHEN the Messages page renders it in an English portal
- THEN the Arabic text MUST be shown with `lang="ar"`
- AND an `aside` named "AI translation" MUST read "Translated by AI from Dutch"
- AND a button "Show the original text" MUST have `aria-expanded="false"`

#### Scenario: The original is one click away

- GIVEN the notice is shown
- WHEN the reader presses "Show the original text"
- THEN the Dutch original MUST be shown with `lang="nl"`
- AND the button MUST read "Hide the original text" with `aria-expanded="true"`

#### Scenario: A message without a translation shows no notice

- GIVEN a message without `translation`
- WHEN it renders
- THEN no notice MUST be shown
