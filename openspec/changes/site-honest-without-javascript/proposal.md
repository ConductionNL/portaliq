---
kind: code
depends_on: []
---

# Proposal: site-honest-without-javascript

## Summary

With JavaScript off, every portal page says so plainly and links to a plain version of the same page, which the server renders: the navigation, the page text, the publication search and the publication detail with its documents. Whatever truly needs JavaScript says so on the plain page instead of leaving a blank.

- Rows: 6.6 "The portal still works with JavaScript off, or degrades honestly" (not a statutory row).
- Wave: 1, size L.
- Depends on: nothing. Reads opencatalogi's public publication endpoint when opencatalogi is installed, and says so honestly when it is not.
- Decision: D10, second answer (Ruben, 2026-10-06): row 6.6 is kept and specified.
- Build rules: openspec/woo-build-rules.md

## Why

Row 6.6 is `no` in our column. Read on `development` at 4e4e5a74:

- `templates/site.php` renders the whole document (`TemplateResponse::RENDER_AS_BLANK`). Server side it emits the head
  (`SiteHead`: title, description, robots, canonical), the skip link, a JSON config block and an empty
  `<div id="portaliq-site"></div>`. Everything a visitor reads is rendered by `js/portaliq-site.js` after boot.
- With scripting off the visitor gets the portal's title in the tab and a skip link to `#pq-main`, a target that only
  the bundle renders. No text, no navigation, no notice. `grep` for `noscript` in `templates`, `src` and `lib` finds
  only two tag deny-lists in the traffic recorder.
- Publications are read in the browser: `FederatedSearchBlock` and `PublicationDetailBlock` fetch
  `/index.php/apps/opencatalogi/api/federation/publications` (and `/{id}`, `/{id}/attachments`) from the visitor's
  browser.

`PortalPageController::site()` keeps the CMS headless (ADR-086): the shell resolves no content beyond what the public
content API gives an anonymous caller. `SiteHead` already reads the page through `CmsReader::page()` with the
anonymous audience for the head. The plain version below is one more consumer of exactly that anonymous read, so it
opens no privileged path.

## What changes

What is realistic, decided from the code:

1. **An honest notice on every page.** `templates/site.php` gains a `<noscript>` block directly after the skip link,
   holding a `<main id="pq-main">` with a short notice in the document language: this site uses JavaScript for its
   interactive parts, here is the plain version of this page. The link keeps the route and the search parameters.
   With scripting on, a browser does not parse `<noscript>` content into the page, so nothing changes for those
   visitors and the bundle's own `#pq-main` stays the only one.
2. **A plain version, rendered by the server.** A new public route `GET /site/plain` renders the same route of the
   same portal as plain HTML with the same stylesheets and no script at all: the portal's title and main menu as
   ordinary links into `/site/plain`, the page title and summary, a markdown body, and per grid widget either its
   text (heading, paragraph, markdown, list, link list) or a sentence saying that this part needs JavaScript, with a
   link to the full page.
3. **Publication search without JavaScript.** A page with a `federatedSearch` widget renders, in the plain version, a
   GET form with the search field and a submit button, the results (title linking to the plain detail page, date,
   summary), the total and previous and next links, using the parameters the block already uses (`_search`,
   `_page`). The server reads the same opencatalogi endpoint the block reads, anonymously, through
   `InstanceLoopback`.
4. **Publication detail without JavaScript.** `/site/plain?route=/publicatie/<id>` renders the title, summary, date,
   category and themes by name, and the documents as download links. A publication that does not exist and one an
   anonymous caller may not read get the same 404 page, as the block does today.
5. **Honest failure.** When opencatalogi is not installed, does not answer, or the widget points at another
   instance, the plain page says the publications cannot be shown here right now and links to the full page. It never
   shows an empty result list as if nothing matched.

## What does not change

- The JavaScript site, its bundle, its routes and its first-load budget.
- What needs a session or a browser: sign-in, the resident's own environment, forms, intake, saved searches, the
  dossier, the editor and the traffic client. The plain version names them and links to the full page; it does not
  reimplement them.
- The content API and opencatalogi's endpoints.

## Fail closed

- The plain version always reads as an anonymous visitor, also when the request carries a session: a
  server-rendered page must never put a resident's data into HTML a shared cache could keep.
- The loopback call to opencatalogi carries no cookie and no authorization header.
- The server only calls a relative endpoint on this instance. A widget endpoint that is an absolute URL is not
  fetched by the server (no request forgery through page content); the plain page links to the full page instead.
- Markdown and every value read from the API are escaped. The markdown converter allows a fixed subset and only
  `http`, `https`, `mailto` and relative link targets.

## Dependencies

None. opencatalogi absent: the publication parts render the honest notice of point 5 and the rest of the page
renders. The e2e job in `code-quality.yml` does not install opencatalogi today; this change adds it to
`e2e-additional-apps` and seeds one publication, so the plain search and detail are tested end to end.

## Wave and done

Wave 1, size L. Done means merged on `development` with CI green. Row 6.6 then reads `yes` (build), and `production`
only once a portaliq store release carries it.
