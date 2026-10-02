# Design: site-mijn-omgeving-components

Read at portaliq `development` b150def5, thematiq PR #892 (`denhaag-component-tokens`), dossiq PR #3249 and learniq PR #1641 on 2026-10-02.

## D1. Den Haag CSS per component, at thematiq's pinned versions

Each component imports the CSS of its own Den Haag package, and nothing else from it:

| Component | Package (version pinned by thematiq#892) |
|---|---|
| `CaseCard` | `@gemeente-denhaag/card` 5.1.4 |
| `ProcessSteps` | `@gemeente-denhaag/process-steps` 4.3.3, `step-marker` 3.1.3 |
| `ActionRow` | `@gemeente-denhaag/action` 4.4.2 |
| `FileItem` | `@gemeente-denhaag/file` 2.5.3 |
| `ContactTimeline` | `@gemeente-denhaag/contact-timeline` 4.1.3 |
| side navigation | `@gemeente-denhaag/side-navigation` 4.2.4, `sidenav` 2.0.0 |
| `DataBadge` | `@gemeente-denhaag/data-badge` 2.2.2 |

- Import path: `@gemeente-denhaag/<name>/dist/index.css`. No JavaScript module of these packages is imported.
- The packages declare `react` as a peer. npm installs it into `node_modules`; it never reaches a bundle because nothing imports it. A build test asserts that no site chunk contains a module under `node_modules/react` or `node_modules/@gemeente-denhaag/*/dist/mjs`.
- Versions are exact, not ranges, and equal thematiq's mapping pins. A bump is a two-repo change: thematiq's mapping first, then portaliq.
- Every component loads on demand, so its CSS lands in its own chunk. The entry has about 1.3 KiB left under its 412 KiB budget (`webpack.site.js`); `npm run build:site` is the gate.
- `REUSE.toml` needs no entry: the CSS stays in `node_modules` and is bundled, not vendored.

`@gemeente-denhaag/components-css` (one 245,559-byte file) is not used: it is too large to load whole and it has no data badge.

## D2. Our markup, their classes

Each component is a Vue SFC under `src/site/components/mijn/` that emits the Den Haag class structure with our own semantics:

| Component | Den Haag classes | Semantics |
|---|---|---|
| `CaseCard` | `denhaag-case-card` | one `<a>` around the card, title as the link name, case type above it, status as text, progress as text ("Stap 2 van 4") plus a decorative bar |
| `ProcessSteps` | `denhaag-process-steps`, `denhaag-step-marker` | `<ol>`, the current step `aria-current="step"`, a done step announced as done |
| `ActionRow` | `denhaag-action` | a list item with one link; the deadline in a `<time>`; the badge text read as part of the link |
| `DataBadge` | `nl-data-badge` (with `--neutral`, `--success`, `--warning`, `--error`) | text, never colour alone ("Nieuw", "Voor 12 oktober") |
| `FileItem` | `denhaag-file` | a link that downloads; name, then "PDF, 84 kB", then source and date |
| `ContactTimeline` | `denhaag-contact-timeline` | `<ol>` newest first, each item a `<time>` and a sentence |
| `DescriptionList` | `utrecht-data-list` look | `<dl>`, used by `DetailCard` |
| `FigureTiles` | own, on Utrecht tokens | `KpiCards` data, value and label in one text run |
| `QuickTiles` | own, on Utrecht tokens | `cta` blocks as links in a list, icon decorative |
| `RecordSwitcher` | own, on Utrecht tokens | a radio group; the choice is the route |
| `ActingForBar` | own, on Utrecht tokens | a region named "Namens wie u werkt" with a switch-back link |
| `EmptyState` | own | a sentence that says what is empty and, when there is one, what to do |
| `Skeleton` | own | grey blocks, `aria-hidden`, plus one visually hidden "Bezig met laden" in a status region |

NL Design System has no CSS for Data Summary (figure tiles), a record switcher, an acting-for bar, empty states or skeletons. Those are ours, on `--utrecht-*` tokens, so the bridge themes them.

## D3. Tokens: thematiq owns them, portaliq reads them

`nldesign-theme-integration` holds that portaliq ships no tokens. That stays.

- Portaliq reads `--denhaag-*` and `--nl-data-badge-*`. It declares none of them.
- thematiq#892 writes a generated `--denhaag-*` and `--nl-data-badge-*` section into `css/public-bridge.css`, each with a literal fallback, so every set themes the components through one mapping.
- `site-links-the-theme-bridge` links that bridge. It is a precondition of this change.
- Belt and braces: each component's own `<style>` sets the properties that carry meaning (text colour, border, focus outline) from `--utrecht-*` tokens. A Den Haag rule whose token is missing then still loses to nothing worse than the Utrecht value.

## D4. `/mijn` opens the portal's home

`/mijn` stops redirecting to the first menu entry (`accountArea.js`) and renders the home:

1. A greeting with the name the header shows ("Welkom, Sanne").
2. "Dit moet u nog doen": open portal tasks (`TasksPage` data), then the rows of every `tasks` block on a home page, sorted by deadline. Left out when empty.
3. The home pages: every page a contribution marks `home: true`, in contribution order, without their `tasks` blocks (already shown in 2). One home page renders as is. Several each get their app's name as a heading.
4. No home page at all: a default overview of running cases (`cases` block over every `cases` collection, open only, limit 4) and the newest messages (`inbox`, limit 2).

A home page is still an ordinary page with its own route; the menu lists it under its `group` unless it declares `menu: false`.

## D5. Page keys

- `group` (string, 1 to 80 characters): the menu heading the page sits under. From #1097.
- `menu: false`: the page keeps its route and leaves the menu.
- `perRecord: <collectionId>`: the menu lists the page once per row of that collection, under a group titled by the row's title fields, linking to the page with that row chosen. The page must be a record page (`record` or `records`) on the same collection, else the key is dropped.
- `records: { collection, titleFields?, subtitleFields? }`: the page shows a `RecordSwitcher` over the collection's rows and scopes its blocks to the chosen row, as `record` does. A bare collection id is read as `{ collection }`. The first row is chosen by default. The choice is in the route (`/mijn/<app>/<page>/<id>`).
- `home: true`: D4.

## D6. Block keys

- `tasks`: `{ type: tasks, collection, dueField?, titleFields?, limit? }`. Rows as `ActionRow` with a deadline badge ("Voor 12 oktober"; "Nog 7 dagen" from seven days out). Each row links to its page.
- `inbox`: `{ type: inbox, collection?, limit? }`. The newest messages of a `kind: inbox` collection, or of every inbox collection when none is named. "Nieuw" on unread ones, unread first, a link to Berichten.
- `cases`: `{ type: cases, collection, open?: true, limit? }`. Case cards; "Alle zaken" leads to the collection's page when there are more.
- `steps`, `documents`, `timeline`: on a record page, each reads the collection's provider of the same name for the open record and renders `ProcessSteps`, `FileItem` rows or `ContactTimeline`. The existing `documents` and `timeline` providers (`DocumentsProviderMethod`, `TimelineProviderMethod`) are reused; `steps` is new (D7).
- `collection` gains `limit` (1 to 50) and `sort` (`{ field, direction: asc|desc }`, the field must be projected). When rows exceed `limit`, a "Bekijk alle ..." link leads to the collection's own page.
- `calendar` gains `range`: `day` (today), `week` (Monday to Sunday of this week, the portal's time zone), `month`, or absent for today's behaviour (everything from today).
- `tasks` takes the same record scope (`recordField`, `recordKey`) and `lookups` as `collection` (`RecordScopeNormaliser`), plus `excludeWhen: { lookup, in }` to leave out rows by a lookup value (handed-in work). `inbox` takes `recordField`.
- `cta` may name a `page` or an internal `route` instead of an `action`; `withRecord: true` presets the open record; `{title}` in the label is the record's title as plain text. Verified on `development`: `normaliseCtaBlock()` accepts an action id and a label only.
- `richText` may carry a `template` with `{field}` placeholders from the open record, values as plain text, a sentence without a value left out or replaced by `whenEmpty` (the assessor's access notice with no end date).
- `collection` may show `display: cards` with `progress: { valueField, totalField, label }` (the trainer's student cards, "120 van 400 uur").
- `records` may take `subtitleLookup`, one hop (the child's group). Two hops are not offered; learniq stamps the name or shows none.
- Opening a record (`navKeyFor` in `src/shared/openRecord.js`) also matches record pages on that collection; today it matches only `collection`, `detail` and `citizenCase` blocks.

## D7. Case progress

A `cases` collection MAY declare:

- `steps: { label?, provider }`: a provider method, beside `timeline` and `documents`, that answers `[{ label, description?, state: done|current|todo, date? }]` for one case (dossiq `caseSteps`, which folds statuses by public label). Portaliq checks the shape and drops entries that do not fit.
- `dueField`: the projected field holding the date the organisation answers by.
- `turnField`: the projected field whose value labels give the card's whose-turn sentence ("U bent aan zet"). The words come from `valueLabels` (#1074, #1097).

The case card shows "Stap 2 van 4" from the steps answer, the due date and the turn sentence. The card asks the steps provider only for the cards on screen (at most the block's limit). Without these keys the card shows title, case type, status and reference.

## D8. Case type name

A `cases` collection with `caseTypeSource` gets `_caseTypeName` stamped on each own and mandated row by `PortalCaseListReader`, read once per list through `CaseTypeReader`, the same names `PortalCaseTypeCatalogue` shows administrators. The card and the table show it. A type that does not resolve shows nothing, never the id.

## D9. Joined-schema filter on `via`

`via` gains two optional members, checked in `PortalObjectReader::verifiedJoinTargets()`. Every reader's join passes there: cases, inbox, collections, timelines, row actions and change notices.

- `when: { field, in: [scalars] }`, the `rowWhen` grammar (`RowWhenNormaliser`): a join row grants only when its field holds one of the values.
- `validUntilField`: a join row whose date in that field lies in the past grants nothing. An empty date grants.

A malformed `when` or `validUntilField` fails the whole `via` closed, to zero rows, as `isValidVia()` does today. This is a security boundary, not presentation: learniq's pupil timetable (withdrawn enrolments), trainer student names (terminated placements) and assessor entries (revoked or expired shares) depend on it.

## D10. Waves

Five PRs. Each fits the budget alone, because every new component loads on demand.

1. `via.when` and `via.validUntilField` (D9). Independent; first, because learniq's assessor entries wait on it.
2. Den Haag CSS wiring, `DataBadge`, `ActionRow`, `EmptyState`, `Skeleton`; `TasksPage` and `MessagesPage` use them; blocks `tasks` and `inbox`.
3. `CaseCard`, `steps` provider and block, `dueField`, `turnField`, case type name, block `cases`; "Mijn zaken" as cards.
4. `FileItem`, `ContactTimeline`, `DescriptionList`; blocks `documents` and `timeline`; `DetailCard` and the case screen use them.
5. Page keys (D5), `limit`, `sort`, `range`, `RecordSwitcher`, `QuickTiles`, `FigureTiles`, side navigation icons and look, `ActingForBar`, the `/mijn` home (D4).

## Risks

- The Den Haag CSS assumes Den Haag's spacing and fonts. Through the bridge it reads the portal's set. Checked on `denhaag` and on thematiq's `example-gemeente` set.
- A Den Haag minor release can rename a class or token. Exact pins and thematiq's drift check make it a reviewed two-repo change.
- `/mijn` changes for every portal. A portal with no tasks, cases or messages shows the greeting and an empty state, never a blank page.
- `react` in `node_modules` as a peer. Harmless to the bundle (asserted), but it adds install weight. Alternative: slice `@gemeente-denhaag/components-css` at build time. Listed as a decision for Ruben.
