# Marketing articles come to portaliq

Ruben's decision of 2026-10-09: marketing articles belong to portaliq. Articles for newsletters, messages and campaign pages move here from pipelinq. This is portaliq's share. The counterpart changes are:

- ConductionNL/pipelinq `marketing-articles-move-to-portaliq`: pipelinq drops its article pages and write path, reads articles from portaliq, answers the usage question for its own templates, blasts and social posts, and runs the data move.
- ConductionNL/design-system: the boards `PqArtikel` and `PqArtikelen` become `portaliq/PtArtikel` and `portaliq/PtArtikelen`, drawn in portaliq's header and side bar with an Articles entry under Website.

pipelinq's KCC knowledge base (xWiki, used on the customer workplace) stays in pipelinq and is not part of this.

## Why

portaliq is the fleet's headless CMS (ADR-086). It already owns `page` (with a markdown body), `media`, `newsItem` and `newsletter`, and it already renders pipelinq's campaign landing pages from an article payload (`contribution-landing-page-action`). A marketing article is the same kind of thing: public text, written once, shown on a page, in a newsletter and in a post. Today it lives in the CRM, so public text has two homes.

portaliq has no reusable article today. `newsItem` is school news with an audience of groups and children, and `page` belongs to one portal. Neither fits a text that several channels reuse.

## What changes

- **A new `article` schema in the `portaliq` register**, the same shape as pipelinq's (title, slug, summary, markdown body, hero image, links, tags, language, status, author, publishedAt, portalPageRef, agentAuthored, agentAuthoredBy) plus `legacyRef`. Same declared lifecycle: draft, review, published, archived, restore.
- **An Articles page and an article page** under Website in the menu, after News: index (table and cards), new, detail with the rendered body, hero image, the agent mark, the lifecycle actions and where the article is used.
- **An `ArticleService` and controller** for what the lifecycle grammar cannot say: the author stamp, slug derivation and uniqueness, the first-publish stamp, and refusing client claims of `agentAuthored` (ADR-088).
- **Usages are asked, not computed.** The article page dispatches `ArticleUsagesRequestedEvent`; apps that reference articles (pipelinq first) add their own usages.
- **A page can show an article.** A `page` with a markdown body can name an article and render it, so a campaign page needs no copy of the text.
- **Import of pipelinq's articles** happens through pipelinq's repair step, which writes into this schema. portaliq only has to accept `legacyRef` and keep it unique.

## Capabilities

### New capabilities

- `marketing-articles`: portaliq owns reusable marketing articles.

## Impact

- `lib/Settings/portaliq_register.json` (schema `article`), example data with the three demo articles
- New: `lib/Service/ArticleService.php`, `lib/Controller/ArticleController.php`, `lib/Event/ArticleUsagesRequestedEvent.php`, routes in `appinfo/routes.php`
- `src/manifest.json`: menu entry `Articles` under Website, pages `Articles`, `ArticleNew`, `ArticleDetail`
- Vue: article editor (markdown editor, hero image from Files), content section and usage section, ported from pipelinq
- The editor role is portaliq's page editor group (`access.pages`), the same as for News.
- Feature tier: V1.
