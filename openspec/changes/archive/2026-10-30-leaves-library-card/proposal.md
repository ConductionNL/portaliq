# leaves-library-card

## Why

The change-proposal queue leaf drew no card of its own on another app's record. nextcloud-vue now hosts a leaf in the
library card (integration-leaf-card, `chrome: "card"` + `onHeader`) and lists leaves in a `linked` card
(linked-integrations-card, `onSummary`), as the boards draw them.

## What changes

- The descriptor declares `chrome: "card"`; the mount forwards `onHeader` and `onSummary`.
- Given `onHeader`, the queue reports its count and empty state and drops its own empty line.
- Given `onSummary`, it draws nothing and reports its count, the noun "change proposals" and a row per proposal.
