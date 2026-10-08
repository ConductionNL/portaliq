# Proposal: site-page-seo-history-and-media

## Why

An editor who runs a portal's public site cannot do three things every web
editor expects: tell search engines what a page is about, go back to what a
page said last week, and reuse an image on more than one page. Three rows in
the portaliq parity matrix (`openspec/parity/capabilities.json`, compared
2026-09-26) name them. Each has two competitors rated `yes`, which is why the
OpenSpec pass of 2026-09-27 decided `build`. They share the page editor and the
site renderer, so they are one change.

**`cmp-site-seo`**, "Set page titles, descriptions and other search-engine
metadata per page." Portaliq `no`, built.state `none`. The matrix evidence:
"templates/site.php:242 sets only the PORTAL's title in <title>, once,
server-side; the page title after that is set client-side via App.vue:887-898
applyDocumentTitle() (document.title, not a server-rendered <title> a crawler
executing no JS would see); no <meta name="description">/og:* tag is emitted
anywhere in templates/site.php or App.vue, even though page.summary's schema
description says it is 'used for... the page's meta description'". Competitors
rated `yes`:

- Open Inwoner Platform: "src/open_inwoner/templates/master.html:15 og:title
  and :17 meta description per page (django-cms page meta [...]);
  src/open_inwoner/configurations/models.py:684 enable_crawler_indexing; sitemap
  src/open_inwoner/urls.py:123 [reached on every public page]".
- Liferay DXP: "page configuration tabs 'General Design SEO Open Graph Custom
  Meta Tags'",
  https://learn.liferay.com/w/dxp/sites/creating-pages/page-settings/page-settings-ui-reference.

**`cmp-site-versions`**, "Go back to an earlier version of a page." Portaliq
`no`, `none`: "No revision/version-history class or schema anywhere in lib/
[...] `page.draftBody` exists but is a single working copy, not a history".
Competitors rated `yes`:

- Open Inwoner Platform: "src/open_inwoner/conf/base.py:325 djangocms_versioning
  (requirements/base.txt:338 v2.5.1): version history, compare and revert
  [reached on CMS toolbar, Manage versions]".
- Liferay DXP: "'Liferay Publications maintains a history of all published
  changes. You can use this publishing history to easily create publications
  that revert earlier changes'",
  https://learn.liferay.com/w/dxp/sites/publishing-tools/publications/reverting-changes.

**`cmp-site-media`**, "Keep images and files in a media library and reuse them
on pages." Portaliq `no`, `none`: "No media-library controller, schema or
component (grep -rln "media.*librar|MediaLibrary" lib/ src/: 0 hits).
`page.heroImage` is a single per-page reference field, not a reusable library".
Competitors rated `yes`:

- Open Inwoner Platform: "src/open_inwoner/conf/base.py:363 django-filer media
  library [...] image pickers in CMS plugins".
- Liferay DXP: "a Documents and Media library per site, and asset libraries
  share files across connected sites",
  https://learn.liferay.com/w/dxp/digital-asset-management/documents-and-media.

## What changes

- **Search-engine metadata per page.** A page gets an SEO section: a title for
  search results, a description, a "keep this page out of search engines"
  switch, and a share image. The site answers each page with a server-rendered
  `<title>`, `<meta name="description">`, `robots`, `canonical` and Open Graph
  tags, so a crawler that runs no JavaScript reads them. `page.summary` becomes
  the description when the SEO description is empty, which is what its schema
  text already promises.
- **Page history.** The page editor shows the published versions of a page,
  newest first, read from OpenRegister's audit trail of the page object.
  "Restore this version" copies that version's content into the page's draft.
  The editor then publishes as usual; nothing goes live on its own.
- **A media library per portal.** Editors upload images and files once, give
  them alternative text, and pick them for a page's hero image or inside a
  page's content. A picked item is referenced, not copied, so replacing it in
  the library updates every page that uses it.

## Rows this closes

| matrix | row | name | own rating | what is missing |
| --- | --- | --- | --- | --- |
| portaliq | `cmp-site-seo` | Set page titles, descriptions and other search-engine metadata per page | no | the fields and server-rendered tags |
| portaliq | `cmp-site-versions` | Go back to an earlier version of a page | no | the history and the restore |
| portaliq | `cmp-site-media` | Keep images and files in a media library and reuse them on pages | no | the schema, the library screen and the picker |

## Existing work it builds on

- `portal-cms-content-model` (open, 0 of 8 tasks) proposes the `media` schema
  among five CMS schemas in an OpenRegister `cms` register. The shipped CMS
  schemas (`page`, `menu`, `glossaryTerm`, `portal`) live in
  `lib/Settings/portaliq_register.json`, so this change puts `media` beside
  them and names that change as the one whose `media` it realises.
- `portal-cms-admin-ui` (open) and the page designer
  (`openspec/specs/portal-page-designer/spec.md`): the editor these sections
  join.
- `portal-headless-content-api` (open): the content API that serves the page,
  and now the media item.
- OpenRegister's object audit trail
  (`GET /api/objects/{register}/{schema}/{id}/audit-trails`) and its object
  files API. No history store and no file store is built here.

## Out of scope

- A sitemap and a robots.txt per portal. They are site-wide, not per page;
  `dem-rm-seo-aeo-center` (deferred) is the row that asks for them.
- Comparing two versions side by side. The history shows who published when,
  and restores; a diff view is a later change.
- Image editing (crop, resize). The library stores what the editor uploads.
