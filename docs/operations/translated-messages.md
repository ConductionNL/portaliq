---
title: Translated messages
sidebar_label: Translated messages
---

# Translated messages

A parent who reads Arabic picks Arabic once, and school messages then show in Arabic. Every translation says that AI made it and from which language. The original is one click away.

## What the parent sees

Under **Messages** the parent picks a language in **Show messages in**. Each message from school then shows:

- the translated text,
- a notice: "Translated by AI from Dutch", with a short sentence in the parent's language,
- a button, **Show the original text**, that opens the message as the school wrote it.

The parent's own messages are never translated. **As written** switches translation off.

School news follows the same choice. Under **News** each item shows its title, then its text in the parent's language with the same notice and the same **Show the original text** button. The title stays as the school wrote it. The **News** page carries the same language picker, so a parent without conversations can pick a language there.

## What you need

Translation runs through Hermiq's message translation feature. It arrives switched off. Your data protection officer acknowledges it under **Settings > Algorithm register** in Hermiq, and an administrator enables it. Until then, and on an instance without Hermiq, every message shows as written. Nothing breaks.

## What is kept

The message, or news item, keeps its original text. Each translation is stored next to it, once per language, with the language it came from, the model that made it and the notice sentence. A message is translated the first time someone reads it in that language, three messages per page load at most; the rest follow on the next load.

## Endpoints

| Method | Path | What it does |
|--------|------|--------------|
| `GET` | `/portal/api/identity/details` | The account holder's own details, including `messageLanguage` |
| `PATCH` | `/portal/api/identity/details` | Set `messageLanguage` to a language tag such as `ar`, or `""` for as written |
| `GET` | `/api/messages/threads/{id}/messages` | The messages; a translated one carries `translation` |
| `GET` | `/api/news/feed` | The published news for this parent; a translated item carries `translation` |

A `translation` holds `targetLanguage`, `text`, `translatedByAi`, `sourceLanguage`, `sourceLanguageDetected`, `model`, `originalRef`, `disclosure` and `disclosureLanguage`.
