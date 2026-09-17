## 1. Wire the declared NL Design System dependency

- [x] 1.1 Imported `Button`, `Heading`, `Paragraph` from
      `@utrecht/component-library-react` in `src/portal/App.jsx`; replaced the
      bare `<button>` / `<h1>`/`<h2>` / `<p>` usages (login, overview, action,
      logout, collection-load buttons all now Utrecht `Button`s with
      appearances; the new field-collection form uses `FormFieldTextbox` +
      `Button`).
- [x] 1.2 Created `src/portal/theme.css` — a portal-OWN token set
      (`--portaliq-*`, named distinctly so it never collides with NC's own
      `--color-*`, which are unavailable on this standalone public surface)
      plus shell layout + a `.theme-utrecht` accent override, mapped via the
      existing `portaliq-shell theme-${config.theme}` root class.
- [x] 1.3 `src/portal/main.jsx` now `import './theme.css'`, so
      webpack.portal.js's previously-unused `style-loader`/`css-loader` rule
      actually emits CSS (verified in the build output: `theme.css` module
      + the Utrecht components' own runtime-injected styles now ship).

## 2. Accessible loading + disabled states

- [x] 2.1 Top-level loading indicator is now a `Paragraph role="status"
      aria-live="polite"`.
- [x] 2.2 Per-collection loading indicator wrapped in `role="status"
      aria-live="polite"`; endpoint-action feedback (✓/✕) also carries
      `role="status"`.
- [x] 2.3 The disabled eHerkenning/DigiD button gains an
      `aria-describedby="portaliq-idp-unavailable"` linked to a visible
      helper Paragraph ("Available once your organisation configures
      eHerkenning/DigiD.").

## 3. Replace window.prompt()

- [x] 3.1 Replaced BOTH `window.prompt()` loops (`createInCollection` AND the
      new `invokeEndpointAction`) with a shared inline
      `src/portal/components/ActionFieldsForm.jsx`: one labelled
      `FormFieldTextbox` per `action.fields` entry + Confirm/Cancel buttons,
      rendered inline where the action button was. A clicked action with no
      declared `fields` runs immediately (no empty form); with fields it opens
      the form.
- [x] 3.2 `FormFieldTextbox` associates its label with the input
      programmatically (no placeholder-only labelling); the form is a real
      `<form onSubmit>` so it is keyboard-submittable (Enter / the Confirm
      button), never prompt-driven.

## 4. Tests + gates

- [ ] 4.1 axe-core a11y check in a Playwright spec — DEFERRED: needs a
      running instance + the Playwright portal spec (itself deferred in
      portal-controller-http-test-coverage); not run as part of this apply
      pass. Static a11y work (aria-live, aria-describedby, programmatic
      labels, focus-visible outline) is in place for it to pass against.
- [ ] 4.2 Manual keyboard-only pass — DEFERRED: needs a running instance to
      click through; not performed in this headless apply pass.
- [x] 4.3 Gates: `eslint src/portal/` clean, `npm run build:portal` succeeds
      (theme.css + Utrecht components bundled). Hydra gates (spdx-headers,
      forbidden-patterns) not run standalone — flag for the PR review stage;
      every new file carries the `SPDX-License-Identifier: EUPL-1.2` header.

## Notes on scope taken

- Utrecht CSS is delivered the way `@utrecht/component-library-react`
  intends: each component injects its own stylesheet at runtime (an
  `insert-style` module per component), so `theme.css` deliberately supplies
  ONLY the shell-level tokens/layout the components render inside of, rather
  than importing every `@utrecht/*-css` package by hand.
- `config.logo` `alt`-text convention (flagged in the proposal for when
  white-label logos land): no `<img>` is rendered yet (the header still shows
  the org NAME as text), so there is nothing to add `alt` to in this pass —
  noted for whoever wires `config.logo` into the header.
