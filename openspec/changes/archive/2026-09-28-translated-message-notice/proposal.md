---
kind: code
depends_on: []
---

# Proposal: translated-message-notice

## Summary

A guardian who picks a language sees school messages in that language, with a visible notice "Translated by AI from Dutch" and a link that shows the original text. portaliq asks hermiq's translation delegate for the translation, duck-typed, and keeps the original, the translation and the provenance on the stored message. This is decision D24 of the learniq competitor round ("AI-made translations are visible"), portal side.

## Motivation

Decision D24 (Ruben, 2026-09-27, `learniq-mi/learniq/_round1/compare/decisions.md`): content translated by the hermiq delegate carries a notice with the source language and a link to the original. `guardian-direct-messages` left translation out on purpose and pointed at hermiq's `message-translation-delegate` (its proposal, "Out of scope"; the `newsItem.body` description says the same).

Competitor evidence: finding row 9.4 "Automatic translation of messages" (`_round1/compare/findings.md`, rung NICE, five sources: parentcom, canvas, social-schools, kwieb, parnassys). Parentcom translates inbox and news as a paid add-on; Social Schools sells a "Vertaalmodule" where parents reply in their own language; Parro ships translation on by default (parnassys round 1, row 15.7). None of the cited sources describes a label that names the source language and links to the original.

## Affected Projects

- [ ] Project: `portaliq`: a `messageLanguage` preference on the portal account, a duck-typed client for hermiq's translation engine, translations stored on `guardianMessage`, a guardian messages page in the portal with the notice, and the notice in the inbox.

hermiq is touched by its own change, `ai-translation-provenance` (hermiq PR #971), which this change reads but does not require.

## Scope

### In Scope

- `portalAccount.messageLanguage` (BCP-47 tag, empty means "show messages as written"), set by the account holder through `PATCH /portal/api/identity/details`, read back through a new `GET /portal/api/identity/details`.
- `guardianMessage.translations`: one server-managed entry per target language with the translated text and hermiq's provenance fields. `body` stays the original.
- `MessageTranslationClient`: calls `OCA\Hermiq\Service\MessageTranslationEngine` only when that class exists, never with a hard dependency; an answer without `translatedByAi: true` is dropped, so an unlabelled translation is never shown.
- `GuardianMessageTranslator`: on `GET /api/messages/threads/{id}/messages`, translates up to three not-yet-translated messages per request into the reader's language, stores them, and attaches `translation` to each row for that language. A reader's own messages and messages already in the reader's language are not translated.
- Portal SPA: a `Messages` page (thread list, messages, language picker), shown when the guardian has a thread; a `TranslatedText` component whose notice is an `aside` landmark with text and a mark, not colour alone, and a button that shows the original. The inbox renders any row carrying a `translation` the same way.
- en and nl strings for the portal SPA and the schema catalogue.

### Out of Scope

- Translating news items. `newsItem` gains no field here; the same component and client serve it in a later change.
- Translating a guardian's reply for the teacher. The teacher side (`MessageStaffController`) is unchanged.
- Posting a message from the new page. It is read-only; posting stays on the existing API.

## Approach

Read-time translation, cached on the message. The guardian's request resolves the reader's `messageLanguage`; the translator walks the thread's messages, reuses a stored entry for that language, and asks the client for at most three new ones. The client calls hermiq's engine in-process with `originalRef: "portaliq:guardianMessage:<id>"` and no `sourceLanguage`, so hermiq detects it. The SPA renders the translation first and the notice under it.

## New Dependencies

None. hermiq is optional and discovered at runtime.

## Impact

- Register 0.36.0: `portalAccount` 0.10.0, `guardianMessage` 0.2.0, both additive. A round 3 lane on the pay screen (D30) may bump the portaliq register in parallel; the landing orders them.
- `GET /api/messages/threads/{id}/messages` rows may carry `translation`; the array shape is unchanged.
- `PATCH /portal/api/identity/details` accepts `messageLanguage`; new `GET /portal/api/identity/details`.

## Cross-Project Dependencies

- Reads hermiq's `ai-translation-provenance` contract (PR #971, stacked on #969). Without hermiq, or with a hermiq that does not label its answers, messages show as written.
- hermiq's gate must answer for a caller without a Nextcloud session; #971 adds that (REQ-011).

## Risks

### Risk 1: A slow provider slows the guardian's page
**Severity:** Medium. **Mitigation:** at most three new translations per request; the rest follow on the next load; every translation is stored, so each message is translated once per language.

### Risk 2: A translation shown without its label
**Severity:** Medium. **Mitigation:** the client drops any answer without `translatedByAi: true`; the component renders no translation without a notice; a test pins both.

### Risk 3: Detection calls a Dutch message foreign
**Severity:** Low. **Mitigation:** a message whose detected language equals the reader's is cached but shown as written; the notice names the detected language so a wrong one is visible.

## Rollback Strategy

Revert the PR. Stored `translations` and `messageLanguage` values stay in the rows and are ignored by the older schema.

## Open Questions

None blocking. Whether translations should also be made at send time for group threads is left for later.
