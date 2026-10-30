# Proposal: publication-page-shows-the-schemas-fields

## Why

The site's publication page reads one publication from opencatalogi's federated endpoint and draws a fixed set of rows: the publication date, the information category and the themes (resident-sees-words-not-codes). That set fits a Woo publication. A publication of another schema, such as a stackiq application (schema `module`), gets only its name and publication date: its type, supplier, licence, website and long description arrive from the API and are dropped (#1645).

Which fields are public is already decided in OpenRegister. Property-level RBAC strips every field an anonymous reader may not see before the answer leaves the API. The page only has to show what it gets.

## What changes

- The page asks for the publication with `_extend=@self.schema`, so the schema comes along in `@self.schema`. The schema (about 10 kB) travels with every answer; portaliq keeps no schema cache of its own. Once ConductionNL/opencatalogi#2061 lands, an anonymous visitor's repeat views are cached by opencatalogi's public-API cache headers on the federation route this page reads (`AnswersCacheably`: an `ETag` over the body and a 304 on a matching `If-None-Match`); a changed schema changes the body and so the tag, so a stale schema is not served beyond opencatalogi's configured max-age. A signed-in visitor, such as an editor previewing the portal on the same instance, then gets `private, no-store` and fetches the full answer on every view. Until then the federation route sends Nextcloud's default `no-cache, no-store, must-revalidate` to everyone.
- After the Woo rows the page shows every other field of the answer as a row. The schema names them (the property's `title`), orders them (`order`, then the schema's own order) and formats them: a `date`/`date-time` reads the way the site's reader writes a date, an enum value reads as its `x-enum-labels` label, a link is a link, `markdown` reads as text lines.
- A property the schema marks `visible: false` stays off the page, the long description and the summary fields included, as it stays out of OpenRegister's own exports. So do an empty value, a file, and a related object known only by its id: no codes on the page.
- The summary falls back to `shortDescription` after `summary` and `description`. A `longDescription` shows under the summary as headings and paragraphs, never as markup.
- The cards variant (board Publicatie) shows the new rows too, in its boxed list, and the long description under the summary.
- Without the schema (a federated peer that ignores the extend), a field's name is put in words, and a UUID is left out as the id of a related object. `visible: false` can then not be honoured.

## Not changed

- A Woo publication (it carries `wooCategory`, or its schema describes one) reads exactly as before: its other fields are the archive's bookkeeping.
- Which fields are public: that stays with the schema's RBAC in OpenRegister. Portaliq adds no visibility rule of its own.
- The documents and the search results.
