---
kind: code
depends_on: [portal-federated-search]
---

# Proposal: home-and-theme-landing-pages

Woo capability programme, round 1, wave 2. Rows 6.20 and 6.23, and the portaliq half of 6.28.

| row | text | our rating today |
| --- | --- | --- |
| 6.20 | A theme or topic has a landing page with an image, a description and its publications | partial (production) |
| 6.23 | The portal shows live counts of what it holds, each linking into a filtered search | no |
| 6.28 | An administrator features a subject on the portal's home page from the register (portaliq half; the row is closed by `opencatalogi/subjects-as-first-class-records`) | no |

No Ruben decision governs these rows.

## Why

What portaliq does today, read on `development` at ca591037:

- `FederatedSearchBlock` searches opencatalogi's `/api/federation/publications` with facets the
  visitor picks. A page cannot embed the block with a facet fixed, so a theme page cannot show "its
  own publications" without the visitor filtering by hand.
- There is no subject page and no block that lists featured subjects.
- Nothing shows how much the portal holds.

opencatalogi's planned change `subjects-as-first-class-records` (wave 1) gives subjects a `featured`
flag, a `slug`, landing data and public routes. This change is the portal side of those routes.

## What changes

1. **A locked facet on the search block** (6.20): the block's props accept `lockedFilters` (for
   example `{themes: '<theme id>'}`). A locked filter is always sent, is shown as a fixed chip
   without a remove control, and is not offered in the facet list.
2. **A subject landing page** (6.20): `/onderwerp/{slug}` renders the subject's image, title and
   description from `GET /index.php/apps/opencatalogi/api/themes/{slug}`, and its publications through
   the search block with the subject locked. An unknown or non-public subject answers the site's
   not-found page.
3. **Featured subjects on the home page** (6.28, portaliq half): a `featuredSubjects` widget lists
   `GET /api/themes?featured=true` in `featuredOrder`, each with image, title, summary and its
   publication count, linking to its landing page.
4. **Live counts** (6.23): a `portalCounts` widget shows totals per category, per subject or per
   period (the author picks one), read from the search endpoint's facet counts, each count a link into
   the search with that filter set. Counts are always read as an anonymous visitor would see them.

## What does not change

- The search endpoint and the subject routes, which are opencatalogi's.
- The search block's behaviour without `lockedFilters`.

## Dependencies

- Planned, opencatalogi, wave 1: `subjects-as-first-class-records`: REQ-SUB-001 (`featured`,
  `featuredOrder`, `slug`), REQ-SUB-002 (`GET /api/themes?featured=true` answering
  `{results: [{id, slug, title, summary, description, image, url, isExternal, featuredOrder, publicationCount}], total}`),
  REQ-SUB-003 (`GET /api/themes/{id}/publications`, `GET /api/themes/{id}` with `publicationsUrl` and
  `publicationCount`). Each side tests its half of these keys.
- Open, portaliq: `portal-federated-search` (5/7), whose block this change extends.

**App absent.** Without opencatalogi the three widgets render their empty state with the sentence
that the publication catalogue is not installed, and the subject route answers not found. An
opencatalogi older than `subjects-as-first-class-records` answers no `featured` filter; the widget
then shows nothing rather than every subject, because the widget checks each row's `featured`.

## Wave and done

Wave 2. Done means merged on `development` with CI green. 6.20 and 6.23 then read `yes` (build), and
`production` only once a portaliq store release carries them. 6.28 reads `yes` when this change and
`opencatalogi/subjects-as-first-class-records` are both merged.
