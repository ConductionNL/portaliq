# Tasks: date-times-read-without-seconds

- [x] 1.1 A datetime cell reads to the minute
  - Files: src/site/components/collections/cells.js
  - Test: tests/date-times-to-the-minute.spec.mjs
- [x] 1.2 An inbox date reads to the minute
  - Files: src/site/pages/inbox/inbox.js
  - Test: tests/date-times-to-the-minute.spec.mjs
- [x] 1.3 Live: read the Vergaderkalender in Dutch
  - :8080 (portaliq 7078a7b), 31 Oct: Vergaderkalender reads "8-10-2026, 19:30"; the inbox reads "30 oktober 2026, 15:40". No time with seconds on either page.
