<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

# Webfonts for the site renderer

Loaded by [`../nlds/nlds-fonts.css`](../nlds/nlds-fonts.css), which explains why
the faces are re-declared there instead of coming from the vendored NLDS
stylesheets.

## What ships here

| File | Face | Licence |
| --- | --- | --- |
| `roboto-400.woff2` | Roboto Regular | Apache-2.0 |
| `roboto-500.woff2` | Roboto Medium | Apache-2.0 |
| `roboto-700.woff2` | Roboto Bold | Apache-2.0 |

Roboto is © Google Inc. and licensed under the Apache License 2.0 — see
`LICENSE-roboto.txt`. It carries the body copy and the navigation, so it is the
face that matters for layout.

## `licensed/` — the drop-in slot

`licensed/` is **gitignored** and ships empty.

The NL Design System reference application also uses commercial font software:
Avenir LT W01 and Gill Sans W01 (Monotype/Linotype) and Tisa Sans Pro. Reading
the `name` table out of the files that application serves shows an explicit
"all rights reserved" plus a notification-of-licence clause. We hold no licence
to redistribute them, so they are not in this repository and must never be
committed here.

A deployment that **does** hold the licence places the file at the name
[`../nlds/nlds-fonts-licensed.css`](../nlds/nlds-fonts-licensed.css) expects:

```
css/fonts/licensed/avenir-lt-55-roman.woff2
```

and the affected headings render in Avenir.

`templates/site.php` links that sheet **only when the file is on disk**, and
that condition is load-bearing rather than tidy. This section previously said
the absent case cost "one failed fetch" and that "nothing breaks". It cost two
console errors on every anonymous page load:

| request | status |
| --- | --- |
| `/apps/portaliq/css/fonts/licensed/avenir-lt-55-roman.woff2` | **401** — Nextcloud answers a missing app asset with 401, not 404 |
| `/75d49df91bd25d7364df.woff2` | **404** — the vendored `nlds-app.css` Avenir face, reached because a failed `@font-face` source falls back to the previous matching rule |

e2e **S24** asserts a public portal logs no console errors, and it failed on
both. Without the licence the page now makes neither request:
`nlds-fonts.css` declares Avenir as `local('Avenir'), …, url(roboto-400.woff2)`
— `local()` first so an installed copy is used with no download, a shipped file
last so the source list resolves and the vendored 404 is never reached. The
rendering is the documented Roboto fall-through either way; only the failures
are gone.
