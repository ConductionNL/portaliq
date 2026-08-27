---
status: proposed
---

# Spec: supplier-portal (i18n locale support)

## ADDED Requirements

### Requirement: The portal shell MUST render user-visible text through a locale-aware translation layer

Every user-visible string in `src/portal/App.jsx` SHALL be sourced from an
English-keyed translation bundle (never a hard-coded literal, and never Dutch
used as the key), resolved at render time against `RUNTIME_CONFIG.locale`. A
missing or unrecognised locale SHALL fall back to English, not silently render
untranslated keys.

#### Scenario: Dutch-speaking supplier sees Dutch
- **GIVEN** `RUNTIME_CONFIG.locale` is `'nl'`
- **WHEN** the portal shell renders the login and dashboard views
- **THEN** every heading, label, and button text renders from `nl.json`

#### Scenario: English-speaking supplier sees English
- **GIVEN** `RUNTIME_CONFIG.locale` is `'en'` (resolved from `Accept-Language`
  or tenant configuration)
- **WHEN** the portal shell renders the same views
- **THEN** every heading, label, and button text renders from `en.json`,
  including the login CTA labels and empty-state copy

#### Scenario: Unknown locale degrades to English, not a blank string
- **GIVEN** `RUNTIME_CONFIG.locale` is an unsupported value (e.g. `'fr'`)
- **WHEN** the portal shell resolves its translation bundle
- **THEN** it falls back to the English bundle — no key ever renders as
  literally itself or as an empty string

## Notes

- **@e2e** covers the `Accept-Language` → rendered-heading-text scenario
  (tasks.md 4.1).
