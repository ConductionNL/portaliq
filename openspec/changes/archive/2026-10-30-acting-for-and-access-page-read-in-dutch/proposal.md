# Proposal: acting-for-and-access-page-read-in-dutch

## Why

Two copy faults seen in the Zuiddrecht go-live rehearsal on 2026-10-15:

1. Linda Bakker, acting for her father, read "U regelt nu zaken voor Namens Henk Bakker". The acting-for bar, the choice of whom you act for and "My cases" read a mandate's `label` as the name of the party (REQ-SMO-008), but the Zuiddrecht example set, the demo data, the schema's own description and the label a granted access request writes ("Granted on request") all treated it as a sentence.
2. The "Toegang tot zaken" page showed English: "Who may arrange matters for you, and for whom you arrange matters." and the DigiD Machtigen note. The account pages (slice e) keep their Dutch in `src/site/pages/e/strings.js`, but nothing read that file at runtime; they only had the shell's bundle, which lacks 87 of their strings.

## What changes

- A mandate's `label` is the party's name as the holder reads it. The example set and the demo data say "Henk Bakker", not "Namens Henk Bakker"; the schema description says so; a granted access request labels the mandate with the party as the asker named it.
- Every account page is wrapped so its `t` falls back to `pages/e/strings.js` in the page's language, the way the inbox pages fall back to theirs (`withBundles`, shared with `pages/inbox/translate.js`). The strings load with the page, not in the site entry.

## Not changed

- The shell's translator and the entry bundle.
- Mandates already stored keep their label.
