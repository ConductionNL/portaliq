# Design: site-page-seo-history-and-media

Read at portaliq `development` `f230e45` (identical to `eeda3fa` outside `openspec/`).

## Where it sits today

- `lib/Controller/PortalPageController.php:233` `site()` renders
  `templates/site.php` for every route of the public site. It resolves the
  portal server side and passes `portalConfig.title`; the page itself is
  chosen in the browser from the `route` query parameter
  (`src/site/App.vue:803-811` `routeFromLocation()`).
- `templates/site.php:242` prints one `<title>`, the portal's. No meta tag.
- `src/site/App.vue:887-898` `applyDocumentTitle()` sets `document.title` from
  `page.title` after the page loads.
- `page` in `lib/Settings/portaliq_register.json`: `title`, `route`, `status`,
  `locale`, `summary` ("used for listings, search results and the page's meta
  description"), `heroImage` (a reference or URL), `body`, `draftBody`,
  `portal`. No SEO field, no media schema.
- `lib/Service/CmsReader.php:201` `page(portal, route, locale, audience)` reads
  one published page, cached by `cacheKey()` (:113) and invalidated on write
  (:356).
- The editor: `src/views/PageLayoutDesigner.vue` and the manifest pages
  `Pages` and `PageDetail` (`src/manifest.json`), gated by
  `PageEditorService::mayEdit()` (`lib/Service/PageEditorService.php:181`).
- OpenRegister (development, `appinfo/routes.php:1344`)
  `GET /api/objects/{register}/{schema}/{id}/audit-trails` lists an object's
  audit trail; `:1453` `POST .../revert` reverts a whole object.

## D1. The server renders the head, from the same read the page uses

`site()` reads the `route` parameter, calls `CmsReader::page()` with the
anonymous audience, and passes a `head` array to the template: `title`,
`description`, `robots`, `canonical`, `ogImage`. The template prints them with
`p()`. A route with no published page gets the portal's title and
`noindex`. Because the read goes through `CmsReader`, a draft or a page gated to
signed-in visitors never leaks its title into the head.

`applyDocumentTitle()` keeps setting `document.title` on client-side
navigation, now preferring `page.seo.title` from the content API.

## D2. SEO fields live on the page

The page carries four optional flat properties: `seoTitle` (up to 70
characters), `seoDescription` (up to 160), `seoNoindex` (boolean) and
`seoImage` (an http(s) address; a media id once D4 lands). The description
falls back to `summary`, the title to `title`. `CmsReader` projects them as one
`seo` object (`title`, `description`, `noindex`, `image`) in the content API so
a headless front end gets the same fields.

Fixed while building (2026-09-29): the first version made `page.seo` a nested
object. The schema-driven page form skips object-typed properties
(`fieldsFromSchema()` in nextcloud-vue), so an editor could never have filled
it in. Flat properties appear in the page form with their length hints, which
is task T03.

## D3. History reads OpenRegister, restore writes the draft

The page editor's History panel calls OpenRegister's
`GET /api/objects/{register}/{schema}/{id}/audit-trails` for the page object
and lists the entries where `status` was `published` or `body` changed, with
who and when. "Restore this version" takes that entry's `body` and writes it to
`draftBody` through the existing page save path. It does not call
OpenRegister's `revert`, which would also roll back `status`, `route` and the
SEO fields, and would publish at once. The editor sees the restored content in
the draft and publishes it.

Fixed while building (2026-09-29): OpenRegister's per-object
`audit-trails` endpoint is admin-only (`AuditTrailController::objects()` calls
`requireAdmin()`: the trail carries actor ids and per-field diffs), while a
page editor may be any member of the configured editor groups. So the History
dialog reads portaliq's own `GET /api/pages/{id}/history`, gated by
`PageEditorService::mayEdit()`, and `PageHistory` reads the trail in process
through `AuditTrailMapper::findForObjectByAction()`. It keeps only rows of
portaliq's own `page` schema, so a uuid of another object lends no trail. A
version is a row whose `changed.body.new` holds a body (only a publication
writes `body`; a draft save writes `draftBody`); a row that only moved the
status to published is listed without a restore. The answer carries the uid or
display name, never the IP address. The restore stays client side: the designer
writes `draftBody` through OpenRegister as any draft save does.

## D4. A media schema beside the other CMS schemas

`media` in `lib/Settings/portaliq_register.json`: `portal` (required), `title`,
`alt` (required for an image), `kind` (`image`, `file`), `status`
(`draft`, `published`), and the uploaded file attached to the object through
OpenRegister's object files. Read authorisation matches `page`: public for a
published item of a public portal.

`heroImage` and `seo.image` accept a media id besides a URL. In markdown, an
image written as `media:<id>` resolves to the item's public address. The
content API gains `GET /api/content/media/{id}`, which streams the file of a
published media item of the resolved portal and 404s otherwise.

The Media page (`src/manifest.json`, index over `media`, scoped to the portal)
uploads and edits items. The page editor gets a picker dialog in `src/dialogs/`
that lists the portal's published media.

## D5. Replacing a file keeps the id

Replacing the file of a media item keeps its id, so every page that references
it shows the new file. `CmsReader::invalidate()` runs for the portal on every
media write, like on a page write.

## Risks

- An editor may delete a media item a page still uses. The delete is refused
  while a published page references the id, and the refusal names the pages.
- Audit trail entries predating this change may not carry a full `body`. Those
  entries show without a restore action.

## What it deliberately does not do

- It adds no second audit or version store.
- It adds no image processing.
