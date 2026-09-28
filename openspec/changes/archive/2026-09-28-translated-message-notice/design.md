# Design: translated-message-notice

## Context

`guardian-direct-messages` ships threads and messages for guardians, API-only, stored in portaliq's own `messageThread` and `guardianMessage` schemas, and read by `MessageGuardianController` on a portal bearer (no Nextcloud session). hermiq's `message-translation-delegate` (PR #969) translates text; `ai-translation-provenance` (PR #971) adds the provenance fields and a gate that answers for a caller without a session. portaliq had no language preference: the only notification preference is `portalAccount.notificationChannels` (per-channel opt-out), so the language preference is added next to it, on the same self-service path.

## Goals / Non-Goals

**Goals**
- A guardian picks a language once and reads messages in it.
- Every translation is labelled, names its source language and keeps the original one click away.
- Both texts and the provenance stay on the stored message.

**Non-Goals**
- News items, staff-side translation, posting from the new page.

## Decisions

### D1. The preference lives on `portalAccount`, beside `notificationChannels`

The brief called it "the existing notification preference for language". No such field existed on development (measured: the register holds `notificationChannels` and nothing language-shaped on the account). The account is where `notification-preferences-per-role` keeps the holder's own delivery choices, and `PATCH /portal/api/identity/details` already writes them for the holder only, so `messageLanguage` joins that path. A `GET` on the same route lets the page show the current choice. Alternative: a separate preference schema; rejected as one more row per guardian for one string.

### D2. Read-time translation, stored per language

Translating when the guardian reads lets each reader have their own language, including one picked after the message was sent, and group threads need no fan-out over every guardian's language. Each result is stored on the message, so a language is paid for once per message. Alternative: translate at send time for every participant; rejected for group threads (every guardian of the group) and for late preference changes.

### D3. Three new translations per request

A provider call takes seconds. The translator stops asking after three new translations in one request; the rest show as written and are translated on the next load. Newest messages first is not needed: messages come back in stored order and the first three untranslated ones are done.

### D4. Duck-typed, in-process, labelled or nothing

`MessageTranslationClient` resolves `OCA\Hermiq\Service\MessageTranslationEngine` by name through the server container when `class_exists()` says it is loaded (Nextcloud autoloads only enabled apps). It calls `translate()` with named arguments `sourceText`, `targetLanguage`, `originalRef`. An older hermiq without `originalRef` throws on the unknown named argument; the client catches every `Throwable` and returns null. An answer without `translatedByAi: true` is dropped: D24 forbids an unlabelled translation, and a missing label on an AI answer is exactly that. OpenRegister's `runAsSystem()` is not used: OpenRegister reserves it for install, migration, repair and seeding; hermiq's gate answers for a session-less caller instead (#971 REQ-011).

### D5. The notice is an `aside`, the original a disclosure button

An `aside` with `aria-label="AI translation"` is a complementary landmark, so a screen-reader user can jump to it and hear it is a translation. The mark (a small "AI" badge) plus the sentence carry the meaning without colour. The original is toggled by a `button` with `aria-expanded` and `aria-controls`: showing text in place is a disclosure, not navigation, so a button is the correct role; it is styled as a link, as the brief asks. The translated text carries `lang` so a screen reader switches voice.

### D6. The language name comes from the browser

`Intl.DisplayNames([portalLocale], {type: 'language'})` names `nl` as "Dutch" in an English portal and "Nederlands" in a Dutch one. Where `Intl.DisplayNames` is missing, the upper-cased tag is shown. `und` shows "Translated by AI" without a name.

### D7. The Messages page appears only for a guardian with a thread

The SPA asks `GET /api/messages/threads` after the session loads; the nav entry is added only when that returns at least one thread, so supplier and citizen portals see no empty tab.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| `messageLanguage`, `translations` | Declarative: schema properties in `lib/Settings/portaliq_register.json` | Data. |
| Calling hermiq and caching the result | Imperative: `MessageTranslationClient`, `GuardianMessageTranslator` | ADR-031 exception: external integration with an NLP model. |
| The notice | Frontend component | Rendering. |

## Seed Data (ADR-001)

- `lib/Settings/portaliq_mock_register.json`: the `portalAccount` demo rows gain `messageLanguage` (one `"ar"`, the others empty); the `guardianMessage` demo rows gain `translations` (one row with an `ar` entry carrying the full provenance, the others empty arrays). Existing rows are extended, not replaced.

## Risks / Trade-offs

- [Provider latency on a guardian's GET] → three per request, stored.
- [hermiq absent or older] → shown as written, no error.
- [Detected language wrong] → the notice names it, so the reader sees what was assumed.
- [Parallel register bump by another round 3 lane] → named in the PR body for the landing.

## Migration Plan

No database migration. Register import (version-gated) adds the optional properties. Rollback: revert; stored values are ignored.

## Open Questions

None blocking.
