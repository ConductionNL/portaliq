# Design: site-nlds-widget-palette

## D1. Every component, its widget and its wave

Placement: `widget` = standalone on the grid; `field` = a field type inside the `form` widget; `part` = drawn inside other widgets; `inline` = inside the text widget; `shell` = the page frame; `none` = not offered. Groups: content (Inhoud), nav (Navigatie), forms (Formulieren), feedback (Terugkoppeling), mijn (Mijn omgeving), layout (Opmaak). CSS: the package the widget imports, "installed" when `package.json` already has it, "own" when no NL Design System CSS exists (D5). Waves: D7.

| # | Component | Today | Placement | Key | Group | Editable fields | CSS | Wave |
|---|---|---|---|---|---|---|---|---|
| 1 | Accordion | X | widget | `nlAccordion` | layout | items (title, text), heading level, first open | `@utrecht/accordion-css` | 2 |
| 2 | Action Group | X | widget | `nlActionGroup` | content | buttons (label, link, kind) | `@utrecht/action-group-css` | 2 |
| 3 | Alert | C | widget | `nlAlert` | feedback | kind (info, ok, warning, error), heading, text | `@utrecht/alert-css` (installed) | 3 |
| 4 | Alert Dialog | C | part | `nlDialog` variant | feedback | see Dialog | `@utrecht/alert-dialog-css` | 3 |
| 5 | Avatar | X | part | in `RecordSwitcher` | mijn | none | own | 5 |
| 6 | Blockquote | T | widget | `nlQuote` | content | text, source | `@utrecht/blockquote-css` | 2 |
| 7 | Breadcrumb Navigation | S | shell | | | | | |
| 8 | Button | F | widget | `nlButtonLink` | content | label, link, kind (primary, secondary, subtle) | `@utrecht/button-css` (installed) | 2 |
| 9 | Calendar | C | widget | `nlCalendar` | mijn | source (an app's calendar block), range | `@utrecht/calendar-css` | 5 |
| 10 | Card as Link | W | widget | `card`, `cardGrid` | content | as today | library | done |
| 11 | Case Card | X | widget | `nlCases` | mijn | collection, open only, limit | `@gemeente-denhaag/card` | 5 |
| 12 | Checkbox | C | field | `checkbox` | forms | label, description, required | `@utrecht/checkbox-css` (installed) | 6 |
| 13 | Checkbox Group | X | field | `checkboxes` | forms | legend, options, minimum | own | 6 |
| 14 | Code | T | inline | | | | `@nl-design-system-candidate/code-css` | 2 |
| 15 | Code Block | T | widget | `nlCodeBlock` | content | code, label | `@nl-design-system-candidate/code-block-css` | 2 |
| 16 | Color Sample | X | none | | | | | |
| 17 | Contact Timeline | C | widget | `nlTimeline` | mijn | collection (record page) | `@gemeente-denhaag/contact-timeline` | 5 |
| 18 | Customizable Text Input | X | field | `textAffixed` | forms | label, prefix, suffix | `@utrecht/customizable-text-input-css` | 6 |
| 19 | Data Badge | X | part | in tasks, inbox, cards | | | `@gemeente-denhaag/data-badge` | 5 |
| 20 | Data Summary | C | widget | `nlFigures` | mijn | source (an app's kpi block) or items (value, label) | own | 5 |
| 21 | Date Input | F | none | replaced by 22 | | | | |
| 22 | Date Input Group | X | field | `date` | forms | label, hint | own | 6 |
| 23 | Date Picker | X | field | `date` with `picker` | forms | label, earliest, latest | `@utrecht/calendar-css` | 6 |
| 24 | Description List | C | widget | `nlDescriptionList` | content | items (term, description) | `@utrecht/data-list-css` | 2 |
| 25 | Dialog | C | widget | `nlDialog` | feedback | button label, heading, text, variant (dialog, modal, alert) | own on `<dialog>` | 3 |
| 26 | Dot Badge | X | part | in menus | | | own | 5 |
| 27 | Drawer | X | widget | `nlDrawer` | feedback | button label, heading, text | `@utrecht/drawer-css` | 3 |
| 28 | Fieldset | C | field | `group` | forms | legend, description, fields | `@utrecht/form-fieldset-css` | 6 |
| 29 | Figure | X | widget | `nlImage` with caption | content | image, alt text, caption | `@utrecht/figure-css` | 2 |
| 30 | File | C | widget | `nlFileList` | content | files from the media library (label) | `@gemeente-denhaag/file` | 5 |
| 31 | File Input | F | field | `file` | forms | label, accepted types, size limit | own | 6 |
| 32 | Form Field | F | part | every field | | | installed | |
| 33 | Form Field Description | F | part | field property | | | installed | |
| 34 | Form Field Error Message | F | part | runtime | | | installed | |
| 35 | Form Field Label | F | part | field property | | | installed | |
| 36 | Form Field Label Suffix | X | part | runtime "(niet verplicht)" | | | own | 6 |
| 37 | Form Field Status | X | part | runtime | | | own | 6 |
| 38 | Form Navigation | X | part | multi-step form | | | own | 6 |
| 39 | Form Summary | X | part | review step | | | own | 6 |
| 40 | Grid | S | shell | the editor grid | | | | |
| 41 | Heading | T | widget | `nlHeading` | content | text, level 1 to 6, look level | `@utrecht/heading-1-css` to `-6` (1 to 3 installed) | 2 |
| 42 | Heading 1 | T | widget | `nlHeading` level 1 | | | | 2 |
| 43 | Heading 2 | T | widget | `nlHeading` level 2 | | | | 2 |
| 44 | Heading 3 | T | widget | `nlHeading` level 3 | | | | 2 |
| 45 | Heading 4 | T | widget | `nlHeading` level 4 | | | | 2 |
| 46 | Heading 5 | T | widget | `nlHeading` level 5 | | | | 2 |
| 47 | Heading 6 | T | widget | `nlHeading` level 6 | | | | 2 |
| 48 | Heading Group | X | widget | `nlHeading` with pre-heading and subtitle | content | pre-heading, subtitle | `@utrecht/heading-group-css`, `pre-heading-css` | 2 |
| 49 | Icon | C | part | in tiles and links | | | library `CnSiteIcon` | |
| 50 | Image | T | widget | `nlImage` | content | image, alt text | `@utrecht/img-css` | 2 |
| 51 | Input Group | X | field | `textAffixed` | forms | see 18 | own | 6 |
| 52 | Language Navigation | X | widget | `nlLanguageNav` | nav | none (the portal's locales) | `@utrecht/alternate-lang-nav-css` | 4 |
| 53 | Link | T | widget | `nlLink` | content | label, link | `@utrecht/link-css` (installed) | 2 |
| 54 | Link List | X | widget | `nlLinkList` | nav | heading, links (label, link, description) | `@utrecht/link-list-css` | 2 |
| 55 | Login Link | S | widget | `nlSignIn` | nav | which ways in (from the portal) | `@utrecht/digid-button-css` | 4 |
| 56 | Logo | S | shell | | | | | |
| 57 | Mark | X | inline | | | | `@utrecht/mark-css` | 2 |
| 58 | Modal Dialog | C | part | `nlDialog` variant | | | own | 3 |
| 59 | Navigation Bar | S | shell | | | | | |
| 60 | Note | X | widget | `nlNote` | feedback | heading, text | `@utrecht/note-css` | 3 |
| 61 | Notification Banner | C | widget | `nlBanner` | feedback | kind, text, can close | own on alert tokens | 3 |
| 62 | Number Badge | C | part | counts in menus and tiles | | | `@gemeente-denhaag/number-badge` | 5 |
| 63 | Number Input | F | field | `number` | forms | label, minimum, maximum, step | `@utrecht/textbox-css` (installed) | 6 |
| 64 | Ordered List | T | widget | `nlList` ordered | content | items, ordered | `@utrecht/ordered-list-css` | 2 |
| 65 | Page Body | S | shell | | | | | |
| 66 | Page Footer | S | shell | | | | | |
| 67 | Page Header | S | shell | | | | | |
| 68 | Page Layout | S | shell | | | | | |
| 69 | Page Number Navigation | C | part | in list widgets that page | | | `@utrecht/pagination-css` | 4 |
| 70 | Paragraph | T | widget | `nlParagraph` | content | text, lead | `@utrecht/paragraph-css` (installed) | 2 |
| 71 | Password Input | X | none | | | | | |
| 72 | Progress Bar | X | widget | `nlProgressBar` | feedback | value, maximum, label | own | 3 |
| 73 | Progress Circle | X | widget | `nlProgressCircle` | feedback | value, label | own | 3 |
| 74 | Progress List | X | widget | `nlSteps` | content | steps (label, state, text) | `@gemeente-denhaag/process-steps` | 5 |
| 75 | Pull Quote | X | widget | `nlQuote` variant pull | content | text, source | own on blockquote tokens | 2 |
| 76 | Radio Button | C | field | `radio` | forms | label, options | `@utrecht/radio-button-css` | 6 |
| 77 | Radio Group | X | field | `choice` (plain or cards) | forms | legend, options, cards | own | 6 |
| 78 | Range | X | field | `range` | forms | label, minimum, maximum, step | own | 6 |
| 79 | Rich Text Content | W | widget | `markdown` | content | as today | installed | done |
| 80 | Root | S | shell | | | | | |
| 81 | Select | F | field | `select` | forms | label, options | `@utrecht/select-css` (installed) | 6 |
| 82 | Select Combobox | X | field | `select` with search | forms | label, options | `@utrecht/combobox-css` | 6 |
| 83 | Separator | T | widget | `nlSeparator` | layout | none | `@utrecht/separator-css` | 2 |
| 84 | Side Navigation | W | widget | `siteNavigation` | nav | as today | own, Den Haag look in `site-mijn-omgeving-components` | done |
| 85 | Skip Link | S | shell | | | | | |
| 86 | Spinner | C | part | loading states | | | own | 5 |
| 87 | Status Badge | C | part | in tables and cards | | | Utrecht badge-status | |
| 88 | Strong | T | inline | | | | `@utrecht/emphasis-css` | 2 |
| 89 | Subscript | X | inline | | | | `@utrecht/subscript-css` | 2 |
| 90 | Superscript | X | inline | | | | `@utrecht/superscript-css` | 2 |
| 91 | Switch | X | field | `switch` | forms | label, description | `@utrecht/form-toggle-css` | 6 |
| 92 | Table | C | widget | `nlTable` | content | caption, columns, rows | `@utrecht/table-css` | 2 |
| 93 | Tabs | C | widget | `nlTabs` | layout | tabs (label, text) | own | 4 |
| 94 | Task List | C | widget | `nlTasks` | mijn | collection or portal tasks, limit | `@gemeente-denhaag/action` | 5 |
| 95 | Task Navigation | X | widget | `nlTaskNav` | nav | items (label, link, state) | own | 4 |
| 96 | Text Input | F | field | `text` | forms | label, description, autocomplete | `@utrecht/textbox-css` (installed) | 6 |
| 97 | Text Area | F | field | `textarea` | forms | label, description, rows | `@utrecht/textarea-css` (installed) | 6 |
| 98 | Toggletip | X | widget | `nlToggletip` | content | term, explanation | own, `@utrecht/tooltip-css` look | 3 |
| 99 | Unordered List | T | widget | `nlList` | content | items | `@utrecht/unordered-list-css` (installed) | 2 |
| 100 | Video | X | widget | `nlVideo` | content | video from the media library, captions, title | own | 2 |
| 101 | YouTube Video | X | widget | `nlYouTube` | content | video id, title | `@utrecht/youtube-video-css` | 2 |

Further Mijn omgeving widgets with no NL Design System row of their own, drawn from `site-mijn-omgeving-components`: `nlInbox` (newest messages), `nlStartTiles` (D6), `nlRecordSwitcher`.

## D2. Palette: groups, search, drag

- `WidgetPaletteDialog` renders one section per group, each with a heading and a list. Order: Inhoud, Navigatie, Formulieren, Terugkoppeling, Mijn omgeving, Opmaak. App widgets that do not render publicly come last under "Alleen in de beheeromgeving", only in the admin designer, as today.
- A search field filters on the Dutch label, the key, the NL Design System name and a few synonyms from the metadata ("kop" finds Heading). The number of hits is announced in a polite live region. An empty result says so.
- Entries are draggable onto the grid (gridstack's external drag-in). Dropping places the widget at that cell with its default size. Click or Enter keeps adding at the first free cell, so the palette works without a pointer (existing requirement "The grid may be edited without a pointer").
- The Formulieren group is enabled only while a `form` widget is selected; its entries add a field to that form (D4).

## D3. Metadata beside each widget, loaders on the site

Today `fieldsFor()` introspects component props, and `LAZY_ON_THE_SITE` imports five components eagerly into the editor to read them. With about forty widgets that does not scale.

Each new widget lives in `src/site/widgets/<key>/` with:

- `<Name>.vue`: the component, which imports its own CSS package;
- `meta.js`: `{ key, group, label, nlds, synonyms, fields, defaultSize, scope }`, no imports.

`src/site/widgets/index.js` exports `loaders` (key to `() => import(...)`) for the site and `metas` for the editor. `WidgetGrid`'s `PUBLIC_WIDGETS` spreads the loaders through `defineAsyncComponent`. `pageWidgetCatalogue.js` reads `metas` for labels, groups, fields and sizes, and keeps introspection only for the existing widgets.

A test asserts that the site entry chunk contains no module under `src/site/widgets/`. The allow-list stays one structure: a key renders publicly if and only if it is in `PUBLIC_WIDGETS` (ADR-084 §5).

## D4. Fields inside a form widget

The `form` widget (`FormBlock.vue`, landing page form) gets a field list in its inspector. "Veld toevoegen" opens the Formulieren group. Each field type has the editable fields in D1. A field's name, label and required flag are always editable. The field list is stored on the form widget's props, in the shape `FormBlock` already submits through portaliq's anonymous create endpoint. Rendering uses the shared field layer of `site-multi-step-forms`, so "(niet verplicht)", the error summary and the date group come with it.

The intake form and contribution actions are not edited here. Their fields come from the published form or the app's schema.

## D5. Our own implementations

On 2026-10-02 npm had no `@utrecht/*-css`, `@nl-design-system-candidate/*-css` or `@gemeente-denhaag/*` CSS for: Avatar, Checkbox Group, Data Summary, Date Input Group, Dialog and Modal Dialog, Dot Badge, File Input, Form Field Label Suffix, Form Field Status, Form Navigation, Form Summary, Input Group, Notification Banner, Progress Bar, Progress Circle, Pull Quote, Radio Group, Range, Spinner, Tabs (Den Haag's `tabs` is 0.1.7), Task Navigation, Video.

Each is built on existing `--utrecht-*` tokens (document, border, focus, link, button), never on literal colours. The theme bridge then themes them with every set. Dialog uses the native `<dialog>` element. Tabs follow the WAI-ARIA tabs pattern, as `MyCasesPage.vue` already does.

## D6. Start tiles from actions

A create or endpoint action MAY declare `summary` (one sentence, at most 200 characters) and `audiences` (a subset of its provider's audiences). `nlStartTiles` shows, for the serving portal, every action that declares a `summary`, as a tile with the action's label and summary. A signed-out visitor who picks a tile signs in first, with the audiences as the hint for the way in. The tile list comes from a public endpoint that returns only label, summary, audiences and route. It never returns fields, endpoints or data.

## D7. Waves

Each wave is one PR within the budget.

1. Palette: groups, search, drag-in, metadata registry, Dutch labels for `siteNavigation` and `form`. No new widget.
2. Content and layout on Utrecht CSS: heading, paragraph, link, link list, lists, quote, button link, action group, description list, image and figure, table, separator, code block, accordion, video, YouTube; the text widget's class map gains strong, sub, sup, mark and code.
3. Feedback: alert, note, banner, dialog (three variants), drawer, progress bar, progress circle, toggletip.
4. Navigation: language navigation, sign-in, task navigation, tabs; page number navigation inside paging widgets.
5. Mijn omgeving: cases, tasks, inbox, timeline, figures, calendar, steps, file list, record switcher, start tiles; after `site-mijn-omgeving-components` waves 2 to 5.
6. Forms: field list in the `form` widget with every field type; after `site-multi-step-forms` wave 1.

## Risks

- About forty new chunks. Each is small and loads only on a page that places it. The build's per-asset limit applies to each.
- Mijn omgeving widgets on a CMS page show subject data. They read the shell's signed-in data or existing scoped endpoints, like `siteNavigation`, and a placement cannot change whose data they show. Signed out, they show a sign-in prompt.
- Video and YouTube embed third-party or large media. YouTube uses the privacy-enhanced domain and loads only after a click. Captions are required for a self-hosted video.
