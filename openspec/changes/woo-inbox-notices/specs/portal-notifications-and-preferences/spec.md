---
status: proposed
---

# Spec: portal-notifications-and-preferences

## Purpose

A notice reads in one language, and the inbox badge counts what the inbox shows.

## ADDED Requirements

### Requirement: A change notice is written in the portal's language only (REQ-NAP-010)

The inbox message portaliq writes for a declared change rule SHALL be written in
one language: the first locale of the organisation's portal, or Dutch when the
portal names none or cannot be read. Its subject and body SHALL NOT hold the same
text in two languages.

#### Scenario: A portal that publishes in English first
- **GIVEN** an organisation whose portal has locales `en`, `nl`
- **WHEN** a handler changes a field a change rule listens to on a resident's case
- **THEN** the message subject is "Z-2026-1 has been updated" and holds no Dutch
- @e2e exclude the portal language is per organisation config; pinned by tests/Unit/Listener/PortalRecordChangeListenerTest.php testTheNoticeIsInThePortalsLanguageOnly

#### Scenario: A portal without a language
- **GIVEN** an organisation whose portal names no locale, or whose portal cannot be read
- **WHEN** the same change happens
- **THEN** the message subject is "Z-2026-1 is bijgewerkt" and holds no English
- @e2e exclude an unreadable portal cannot be staged on a live instance; pinned by tests/Unit/Listener/PortalRecordChangeListenerTest.php testWithoutAPortalLanguageTheNoticeIsDutch

### Requirement: The inbox badge counts the unread messages the inbox shows (REQ-NAP-011)

When the inbox has loaded its rows, on the React portal and on the Vue site, the unread badge SHALL show the number
of loaded rows not marked read, also when notices arrived after sign-in.

#### Scenario: Notices written after sign-in
- **GIVEN** a resident signed in with 2 unread messages
- **AND** a background job writes 6 more notices for them
- **WHEN** they open the inbox
- **THEN** the badge says 8
- @e2e exclude the e2e drives the API, not a renderer; pinned by tests/inbox-unread.spec.mjs (React portal) and tests/site-inbox-pages.spec.mjs (Vue site)
