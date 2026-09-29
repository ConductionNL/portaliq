---
kind: code
---

## Why

`webpack.portal.js`'s own header comment describes the portal bundle as
"React + NL Design System" and `package.json` declares
`@utrecht/component-library-react@^14.0.0` as a dependency (the NL Design
System's official React component set — the same family the company's ADR-010
requires: "ALL UI: CSS custom properties from NL Design System tokens... WCAG
AA mandatory"). Grepping the entire `src/portal/` tree shows this dependency is
**never imported**:

```
$ grep -rn "utrecht" src/portal/
(no matches)
```

`src/portal/App.jsx` renders exclusively bare, unstyled HTML elements (`<div>`,
`<header>`, `<button>`, `<ul>`/`<li>`, `<article>`) and no `.css`/`.scss` file
is imported anywhere in `src/portal/` (webpack.portal.js:44-46 wires a CSS
loader rule, but nothing feeds it). `main.jsx:16` sets `theme: 'utrecht'` in
the default `RUNTIME_CONFIG`, but that value is never read to apply an actual
Utrecht/NL-DS theme class or token set — it is dead configuration.

Net effect for the public-facing surface (external suppliers/clients, who are
explicitly NOT Nextcloud users and so get none of `@nextcloud/vue`'s baked-in
accessibility work): every visual affordance — focus rings, contrast, spacing,
button/link styling, form field association — is whatever the visitor's
browser default happens to be, with no verified WCAG AA compliance and no
design-system theming despite both being declared goals of this component.
Concretely:

- `src/portal/App.jsx:159-161` — the disabled eHerkenning/DigiD login buttons
  give no visible or programmatic explanation for why they're disabled
  (no `aria-describedby`, no helper text) — a screen-reader user hits a
  disabled control with only its raw label.
- `src/portal/App.jsx:150-151` and `:195` — loading state (`{state.loading &&
  <p>…</p>}`, `{loaded?.loading && <span> …</span>}`) is not in an
  `aria-live` region, so assistive tech gets no announcement when content
  finishes loading — a sighted user sees the ellipsis resolve, a screen-reader
  user gets silence.
- `src/portal/App.jsx:81` — `window.prompt()` for the "create" action's field
  collection is the exact pattern the company's own ADR-004 already bans for
  the Vue surface ("NEVER `window.confirm()` or `window.alert()` — use
  `NcDialog` or `CnFormDialog` (WCAG, theming)") — `window.prompt()` is the
  same class of un-themeable, unlabelled, un-testable native dialog, just the
  React equivalent gap.
- No `alt` text is applicable today (no `<img>` in the shell), but the
  `config.logo` field the white-label config work
  (`portal-white-label-runtime-config`) is expected to introduce will need one
  from day one — flagged here so the two changes land the same convention.

## What Changes

- Import `@utrecht/component-library-react` primitives (`Button`, `Heading`,
  `Paragraph`, at minimum) in `src/portal/App.jsx` in place of bare HTML tags,
  so the shell actually uses the declared design system instead of only
  depending on it.
- Add a `src/portal/theme.css` (or per-theme CSS files matching
  `RUNTIME_CONFIG.theme`) importing NL Design System CSS custom properties;
  wire it into `webpack.portal.js`'s existing (currently unused) CSS rule.
- Add `aria-live="polite"` regions around the loading indicators
  (`App.jsx:150-151`, `:195`) so assistive tech is notified when contribution
  / collection data finishes loading.
- Give the disabled DigiD/eHerkenning buttons a visible + programmatic
  explanation (helper text tied via `aria-describedby`) instead of a bare
  `disabled` attribute.
- Replace `window.prompt()` in `createInCollection`
  (`src/portal/App.jsx:78-97`) with an inline, labelled, NL-DS-styled form
  (coordinate with `portal-spa-i18n-locale-support` task 3.2 — the same edit
  also externalises the field labels; do this once, not twice).
- No change to auth/session/data-fetching logic.

**BREAKING**: none — purely additive styling/markup; no API or route changes.

## Capabilities

### Modified Capabilities
- `supplier-portal`: the white-label shell requirement gains an explicit WCAG
  AA / NL Design System compliance clause (today implied by the "NL Design
  System" framing in code comments, never a checked requirement).

## Impact

- `src/portal/App.jsx` — swap bare elements for Utrecht components, add
  `aria-live` regions, replace `window.prompt()`.
- New: `src/portal/theme.css` (or `theme/<name>.css` per `RUNTIME_CONFIG.theme`).
- `webpack.portal.js` — import the theme CSS from the entry point.
