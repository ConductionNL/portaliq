---
kind: code
depends_on: []
---

# Proposal: portal-identity-from-the-admin

Woo capability programme, round 1, wave 1. Rows 6.22 and 15.8.

| row | text | our rating today |
| --- | --- | --- |
| 6.22 | An administrator uploads the organisation's logo, favicon and hero image from the product | partial (build) |
| 15.8 | The portal names its own organisation type correctly | partial (production) |

Implements the TOOI half of Ruben's decision D3: TOOI value lists have one copy, in OpenRegister's
concept register, so the organisation type is read from there and not bundled again here.

## Summary

Let an administrator upload the organisation's logo, favicon and hero image in the portal admin, and set the organisation type from the TOOI list so the portal names its own kind of organisation correctly.

- Rows: 6.22 "An administrator uploads the organisation's logo, favicon and hero image from the product" and 15.8 "The portal names its own organisation type correctly" (neither statutory).
- Wave: 1.
- Depends on: nothing before it. The TOOI organisation type list comes from `opencatalogi/woo-value-lists-on-the-concept-register` (https://github.com/ConductionNL/opencatalogi/issues/1780) and OpenRegister's bundled TOOI schemes; until it is in the concept register the picker says the list is not installed.
- Decision: D3 (2026-10-05), the TOOI half: TOOI value lists have one copy, in OpenRegister's concept register.

Build rules: openspec/woo-build-rules.md

## Why

What portaliq does today, read on `development` at ca591037:

- `#portal.logo` is a free string, "URL or object reference". There is no favicon field and no hero
  field on the portal. `templates/site.php` (around line 262) uses the logo as the tab icon, then the
  theme app's `img/logos/<theme>.svg`, then portaliq's own mark. So an administrator cannot set a
  favicon apart from the logo, and a hero image is set per block, not for the portal.
- The `media` schema and the media library exist (`check:media-library`), so uploaded images have a
  home already.
- The portal does not know what kind of organisation runs it. The signed-in area says
  "Van de gemeente" (`src/site/components/mijn/strings.js` line 70) on every portal, also for a
  water authority or a province.

## What changes

1. `#portal` gains `favicon` and `heroImage`, each a reference to a `media` object, and `logo`
   accepts a `media` reference beside the existing URL. The portal settings form picks each from the
   media library, or uploads into it.
2. The site head emits the favicon in this order: `#portal.favicon`, then `#portal.logo`, then the
   theme's icon, then portaliq's own mark. The hero block uses `#portal.heroImage` when the block
   sets no image of its own.
3. `#portal` gains `organisationType`: the TOOI organisation type URI, with the label stored beside
   it when picked (`organisationTypeLabel`). The picker reads the TOOI organisation type scheme from
   OpenRegister's concept register. The shell uses the label wherever it names the organisation's
   kind: the signed-in area's "Van de ..." line, the footer, and the page metadata
   (`<meta name="DCTERMS.creator">` with the organisation and its type).

## What does not change

- The theme (`portal-theme-application`, built as written) and its tokens.
- The media library itself.

## Dependencies

None planned before it. The TOOI organisation type scheme in OpenRegister's concept register comes
from `opencatalogi/woo-value-lists-on-the-concept-register` (wave 2) and OpenRegister's bundled TOOI
schemes (SKOS-003). When the scheme is not in the concept register, the picker says the list is not
installed and offers nothing; the stored value is kept and the shell keeps using its stored label.
A portal without `organisationType` says "Van de organisatie", never "gemeente" by default.

## Wave and done

Wave 1. Done means merged on `development` with CI green. 6.22 and 15.8 then read `yes` (build), and
`production` only once a portaliq store release carries them.
