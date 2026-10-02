---
kind: code
depends_on: [inbox-shows-portal-messages]
---

# Proposal: woo-inbox-notices

## Why

Filming the Woo journey J6 on 2 October showed a resident's inbox with six
identical rows: "Fietspad Lindelaan is bijgewerkt / Fietspad Lindelaan has been
updated". The badge on "Berichten" said 2.

- Six rows: the saved search had six real matches. opencatalogi handed each over
  by saving `lastNotifiedAt`, and its change rule made portaliq write its generic
  change notice per save. The notice named the search, not the publication.
  opencatalogi now writes the match message itself (opencatalogi
  `saved-searches-and-alerts` D4) and declares no change rule on the search.
- Two languages: `PortalRecordChangeListener` glued the Dutch and the English
  text into one subject and one body. A resident reads one language.
- The badge: the shell reads the unread count once, at sign-in. Notices a
  background job writes later never reached it.

## What changes

- The change notice is written in the language of the organisation's portal: its
  first locale, else Dutch. Never two languages in one string.
- The inbox, on the React portal and on the Vue site, hands the unread count of the rows it loaded to the badge.

## Not in this change

- Other writers still glue Dutch and English: `SubmissionReceiptService`,
  `NotificationDispatchJob` (e-mail), `PortalTaskDeliveryJob`. Same fix, separate
  code paths.
