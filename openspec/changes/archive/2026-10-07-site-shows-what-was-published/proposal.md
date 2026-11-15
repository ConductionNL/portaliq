---
kind: code
depends_on: [portal-in-place-editing]
---

# Proposal: site-shows-what-was-published

## Why

An editor publishes a page in place on the site, leaves edit mode, and the tab
still shows the page as it was before. Found while filming the Woo journeys on
:8080 (02 Oct 2026).

Two things together cause it:

- `/api/content/page` is served `public, max-age=300` to every reader without
  a resident bearer. An editor reads the site with their Nextcloud session and
  no bearer, so their browser keeps the old page for five minutes.
- The editor emits `saved` after a publish, but the site never listened, and
  leaving edit mode read the page with an ordinary fetch, which the browser
  answered from that cache.

## What changes

- The site re-reads the page past the browser cache (`cache: 'reload'`) after
  the editor publishes and when the editor leaves edit mode.
- The editor bundle's `mount()` passes `onSaved` through, so the `saved` event
  reaches the site.
- The content API answers a signed-in Nextcloud user `private, no-store`.
  Anonymous visitors stay `public, max-age=300, must-revalidate`, so a CDN in
  front keeps caching what visitors read.

## Out of scope

- Saving a draft does not change the public page, so it does not re-read it.
