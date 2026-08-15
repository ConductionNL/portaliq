---
kind: code
---

# Proposal: portal-theme-application

## Summary

Make the site renderer actually apply a themiq theme and emit NL Design
component markup, so a `website`'s `theme` reference changes what a visitor
sees. Today it changes a class name and nothing else.

## Motivation

Measured on the rig, 2026-08-15:

```
site=open-tilburg → root class "pq-site vng-theme"
site=open-venray  → root class "pq-site venray-theme"
--pq-heading-color                  → UNSET on both
computed heading colour             → rgb(26,26,26) on BOTH
elements carrying NL Design classes → 0
```

Two differently-themed sites render identically. The renderer sets the theme
class the fleet convention expects, no stylesheet defines tokens for it, and
every colour falls through to a hard-coded fallback inside the component's own
`<style>` block.

So three things claimed elsewhere are currently untrue:

- ADR-086 §6 — a website references a themiq theme, and Portaliq ships no
  theming of its own. It ships *only* its own.
- ADR-086 §7 — NL Design conformance comes from tokens plus component CSS.
  Neither is present.
- `docs/portal-parity.md` — "per-site theme ✓". Corrected there.

**This is a repeat of a defect this fleet already paid for.** In phase one, a
Tilburg deployment set `<body class="vng-theme">` while no vng tokens loaded,
and every token fell back — the heading rendered black instead of `#1a1a1a`.
The failure looks like nothing: the page renders, the colours are plausible,
and a screenshot review passes. It is found by asking the DOM what colour it
computed, which is why that assertion is the centre of this change.

## Affected Projects

- [ ] `portaliq` — load the resolved site's themiq token set; emit NL Design
      component classes; declare the themiq dependency in `appinfo/info.xml`;
      replace the `--pq-*` fallbacks.
- [ ] `nldesign`/`themiq` — expose token sets to a public origin. They are
      currently served to authenticated Nextcloud pages.

## Design notes

**Tokens come from themiq, not from here.** The renderer loads the token
stylesheet for the resolved theme and stops defining colours of its own. Where
a fallback is genuinely needed it must be visibly neutral, not a plausible
brand colour — a wrong-looking page is a reported bug; a plausible-looking one
is not.

**NL Design conformance is markup plus tokens**, per ADR-086 §7 and the
measured fact that `@utrecht` ships 95 CSS packages against 17 React ones. The
components emit NL Design component classes; no React design-system package is
introduced.

**Render-blocking, and per site.** Phase one established that appending the
theme stylesheet while the head parses avoids a flash of the wrong branding.
The same applies here, with the added constraint that the variant is only known
after the site resolves.

**An unresolvable theme is reported, not rendered around** (ADR-086 §6). A site
whose theme does not load should look obviously unstyled rather than quietly
default — the whole failure mode above is "quietly default".

## Risks

- **The test is the deliverable.** Any assertion weaker than "these two sites
  compute different colours for the same element" reproduces exactly the
  false confidence being fixed. Asserting the class name, the token file's
  presence, or the API's theme string are all things that already pass today.
- **Loading tokens at a public origin is a new exposure surface** for themiq,
  which has so far served authenticated pages only.
- **Bundle weight.** A token set is render-blocking CSS on a public,
  first-visit, mobile surface; phase one moved 527 KiB out of exactly this
  path. Only the active theme may load.
