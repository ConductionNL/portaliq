# Tasks: marketing-articles-from-pipelinq (portaliq)

Spec only in this PR. Tier V1. pipelinq's `marketing-articles-move-to-portaliq` waits on sections 1 to 3.

## 1. Schema

- [x] 1.1 Add `article` to `lib/Settings/portaliq_register.json` with pipelinq's properties, lifecycle and `legacyRef`; add the three demo articles to the example data. — the `article` schema (pipelinq's properties and lifecycle, plus `legacyRef` and `organisation`; organisation-scoped in `SchemaTenancy`) is in the register (0.111.0), with pipelinq's three demo articles as seed objects and en/nl catalogue keys for its strings. The import itself (the acceptance line) was not run here: it needs a live instance.
  - spec_ref: `specs/marketing-articles/spec.md#requirement-an-article-holds-its-body-as-markdown-and-its-own-identity`
  - acceptance: the register imports with no `PARTIAL IMPORT` in the log; `occ openregister:schema` lists `article` in register `portaliq`

## 2. Write path

- [x] 2.1 Port `ArticleService` and `ArticleController` (create, update, publish, archive, transition, usages, show, index), with the slug, author, first-publish and agent-mark rules. — `lib/Service/Articles/ArticleService.php`, `ArticleStore.php`, `lib/Controller/ArticleController.php` (gated by the new `portal.write-articles` action), routes and openapi.json; `ArticleServiceTest`, `ArticleStoreTest`, `ArticleControllerTest`.
  - spec_ref: `#requirement-an-article-moves-through-a-declared-lifecycle`, `#requirement-an-agent-drafted-article-is-marked-as-such`
  - files: `lib/Service/ArticleService.php`, `lib/Controller/ArticleController.php`, `appinfo/routes.php`, tests
  - test: `vendor/bin/phpunit --no-coverage --filter Article`
- [x] 2.2 `ArticleUsagesRequestedEvent` and the usages endpoint; portaliq contributes pages that name the article. — `ArticleUsagesRequestedEvent` and `GET /api/articles/{id}/usages` are built (`ArticleUsagesRequestedEventTest`); `ArticlePageUsagesListener` adds every CMS page whose body names the article by id or slug, drafts included, linked to the page in the admin (`ArticlePageUsagesListenerTest`).
  - spec_ref: `#requirement-an-article-reports-where-it-has-been-used`

## 3. Pages

- [ ] 3.1 Menu entry Articles under Website after News; pages `Articles`, `ArticleNew`, `ArticleDetail` as drawn on `portaliq/PtArtikelen` and `portaliq/PtArtikel`.
  - spec_ref: `#requirement-a-marketer-writes-and-reads-an-article-in-portaliq`
  - files: `src/manifest.json`, ported Vue components, `l10n/`
- [x] 3.2 A markdown page can name an article and the site renders it. — `page.body.articleRef` (page 0.8.0) names an article by id or slug; `CmsReader` caches the reference with the page and reads the article per request through `PageArticle` (published or archived only, same organisation; `app:<app>/<file>` hero images resolve to that app's `img/`), and the site renders it under the page's markdown (`PageArticleBlock`). PHPUnit `PageArticleTest`, `CmsReaderMediaTest`; `node --test tests/page-article.spec.mjs`
  - spec_ref: `#requirement-a-page-can-show-an-article`

## 4. Verify

- [ ] 4.1 `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, `npm run lint`, `npm run test:l10n` once before push.
- [ ] 4.2 e2e: `tests/e2e/articles.spec.ts` covers the scenarios that are not excluded.
