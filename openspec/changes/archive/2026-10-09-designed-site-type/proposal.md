## Why

Proof run 2 (08 Oct, fresh install) measured, on all four school portals, `body` computing the
vendored Roboto and the hero heading in the body face (Red Hat Text instead of Red Hat Display,
Barlow instead of Barlow Semi Condensed). Measured on :8092 itself: the content (paragraphs,
buttons, Utrecht headings) already read the set's faces. Two places did not:
- `body`: the designed-site rule named only `.pq-site`, so `body` kept the literal
  `Roboto, arial, sans-serif` from the vendored `nlds-app.css`.
- A heading without an Utrecht heading class, such as the hero's `h1.ac-hero__title`, inherited the
  body face from that same rule.

## What Changes

- `css/site-theme.css`: on a portal with the designed header, `body` reads
  `--utrecht-document-font-family` too, and every `h1` to `h6` reads
  `--utrecht-heading-font-family` at zero specificity (`:where()`). So a rule that names a
  heading's own family, such as the Utrecht heading levels, still wins.
- `tests/site-look/designed-site-type.spec.mjs`.

## Verified live

On :8092 with this sheet, `body`, `main p` and buttons compute the set's body face on all four
schools, and the hero `h1` and `main h2` compute the heading face (Red Hat Display, Barlow Semi
Condensed; Lexend and IBM Plex Sans name one face for both).

## Impact

- Only portals with the designed header. Others keep their faces, as the existing rule intends.
