# Proposal: date-times-read-without-seconds

## Why

Rehearsal R3, 16 October: the Vergaderkalender showed meetings as "24-9-2026, 19:30:00". A `datetime` column and an inbox date were formatted with the browser's default, which prints seconds. A resident never needs the seconds of a meeting or a message.

## What changes

- A `datetime` cell and an inbox date print the day and the time to the minute, in the page's language ("24-9-2026, 19:30"; "24/09/2026, 19:30" in English).

## Not changed

- A `date` cell, which prints the day only.

## Rollback

Revert the commit.
