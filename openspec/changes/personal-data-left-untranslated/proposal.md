---
kind: code
---

# Proposal: personal-data-left-untranslated

## Why

A Polish resident lets her browser translate Mijn Zuiddrecht. The page greets "Goedemorgen, Sanne Visser" as "Good morning, Sanne Fisherman", and her street "Lindelaan" becomes "Linden Lane". On a page that also shows her case, that reads like the municipality has her details wrong.

NL Portal marks the user's name and the represented party with `translate="no"` (`OverviewPage.tsx:102`, `:109`). Portaliq marks nothing (no `translate="no"` in `src/site`).

## What changes

- **Names and personal data are marked `translate="no"`:** the resident's name in the greeting and the account menu, the name of the party she acts for, addresses, e-mail addresses, phone numbers, licence plates and reference numbers (case numbers, BSN shown masked).
- **Only the value, never the label.** "Uw adres" still translates; "Lindelaan 12" does not.
- **One helper.** A small component `NoTranslate` wraps a value, so every page marks values the same way and a test can find unmarked ones.

## Rows covered

- `thm-no-translate-personal-data` (decision 101). No board: it is an attribute on values the boards already draw (MijnOverzicht, MijnGegevens, the header menu).

## Out of scope

- The portal's own translations (`thm-portal-chrome-i18n`, `thm-content-i18n`).
