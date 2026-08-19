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
`nlds-fonts.css` expects:

```
css/fonts/licensed/avenir-lt-55-roman.woff2
```

and the affected headings render in Avenir. Without it the browser records one
failed fetch for that face and falls through to Roboto. Nothing breaks; the
page is simply not glyph-identical to a licensed deployment.
