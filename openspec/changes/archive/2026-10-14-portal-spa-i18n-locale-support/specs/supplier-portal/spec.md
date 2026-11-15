---
status: proposed
---

# Spec: supplier-portal (i18n locale support)

## ADDED Requirements

### Requirement: The public site MUST render its own words through a locale-aware translation layer

The site's own user-visible words SHALL come from an English-keyed bundle (`src/shared/i18n/en.json`, `nl.json`), never a hard-coded Dutch literal and never Dutch used as the key. `createTranslator(locale)` in `src/shared/i18n/index.js` binds `t(key, vars?)` to one locale. An unknown or unsupported locale SHALL fall back to the English bundle, and an unknown key SHALL render as its English source text, never as an empty string. The server resolves the locale from the portal's declared locales and the first `Accept-Language` tag (`SiteShell`), and `PortalOrganisationConfigService::normaliseLocale()` keeps it to `nl` or `en`.

#### Scenario: A Dutch-speaking visitor sees Dutch
- **GIVEN** the resolved locale is `nl`
- **WHEN** the site renders its own words
- **THEN** each comes from `nl.json`

#### Scenario: An English-speaking visitor sees English
- **GIVEN** the portal declares `en` and the visitor's first `Accept-Language` tag is `en`
- **WHEN** the site renders the same words
- **THEN** each comes from `en.json`

#### Scenario: An unknown locale falls back to English, not to a blank string
- **GIVEN** the locale is an unsupported value such as `fr`
- **WHEN** the translator resolves its bundle
- **THEN** it uses the English bundle, and no word renders empty

## Notes

- Built on the site shell after the React portal (`src/portal/`) was removed. Tests: `PortalPageControllerTest::testSiteAlwaysPassesANonEmptyLocale`, `::testSiteLocaleFollowsThePortalsDeclaredLocales`, `PortalOrganisationConfigServiceTest` locale cases.
