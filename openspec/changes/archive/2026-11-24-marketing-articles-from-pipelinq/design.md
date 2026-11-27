# Design: marketing articles come to portaliq

## Decisions

### 1. A new schema, not `page` or `newsItem`

`page` belongs to exactly one portal (cms-content-model requires a website reference) and `newsItem` carries a school audience. An article is reused by a newsletter, a social post and several pages, so it belongs to the organisation and to no portal. The schema keeps pipelinq's shape exactly, so pipelinq's repair step copies properties one to one. `legacyRef` (unique when set) records where a moved article came from.

### 2. The code moves with the schema

pipelinq's `ArticleService` (create, update, publish, archive, transition, slug and author rules), `ArticleController` and the Vue components (`ArticleEditModal`, `ArticleFormView`, `ArticleContentSection`, `ArticleUsageSection`, `ArticleDetailFormDialog`) are ported, not rewritten. The pages use the same declarative index and detail types. Namespace and routes become portaliq's: `/apps/portaliq/api/articles`.

### 3. Usages through a string-named event

`OCA\Portaliq\Event\ArticleUsagesRequestedEvent` carries the article id and collects entries `{id, title, kind, app, url}`. portaliq dispatches it when the usage section loads. A listener in another app registers only when the event class exists. portaliq itself contributes pages whose body names the article. Nothing is stored on the article.

### 4. A page renders an article by reference

A markdown `page` body may carry `articleRef`. The site renders the article's published body and hero image. An archived article keeps rendering on pages that already name it, matching the rule that archiving keeps references intact.

## Risks

- **Order with pipelinq.** pipelinq removes its pages only after this ships. Until then both exist; pipelinq's repair step is the switch.
- **Slug collisions** with articles written here before the move. pipelinq's repair step suffixes `-pipelinq` and reports it.

## Not in scope

- Newsletters stay where they are: pipelinq's blasts and portaliq's school `newsletter` both reference articles by id.
- The pipelinq knowledge base.
