# Proposal: site-nlds-widget-palette

## Why

Ruben decided on 2026-10-02 that basically every NL Design System component must be placeable as a drag-and-drop widget in the site editor. The programme's matrix (`nlds-widget-matrix.md`) compared the 101 components on nldesignsystem.nl with the editor's palette. Today an editor can place 16 widgets, and three of them are NL Design System components.

### The matrix, verified against `development` (b150def5)

Read: `src/site/components/WidgetGrid.vue` (`PUBLIC_WIDGETS`), `src/lib/pageWidgetCatalogue.js`, `src/editor/widgetForms.js`, `src/editor/WidgetPalettePanel.vue`, the three form renderers, `MarkdownBlock.vue`, and `@conduction/nextcloud-vue/public` 2.57.3 as installed.

Confirmed:

- The 16 public widget keys: `markdown`, `siteNavigation`, `contributions`, `federatedSearch`, `publicationDetail`, `form`, `intakeCatalogue`, `intakeForm`, `intakeStatus`, `hero`, and from the library `search`, `section`, `cardGrid`, `card`, `emptyState`, `glossary`. `hero` is portaliq's `HeroBlock` over the library's key.
- The three placeable NL Design System components: Card as Link (`card`, `cardGrid`), Rich Text Content (`markdown`) and Side Navigation (`siteNavigation`).
- The palette is one flat list. It has no groups and no search. An editor clicks an entry; the widget lands on the grid and is then moved and resized there. Non-public dashboard widgets from the library are listed and marked; the portal edit mode hides them (`publicOnly`).
- `PUBLIC_LABELS` has no Dutch label for `siteNavigation` and `form`, so the palette shows "Site navigation" and "Form".

Corrected (the matrix was wrong on these):

| Component | Matrix | Actual | Evidence |
|---|---|---|---|
| Checkbox | F | C | no form renderer draws a checkbox; checkboxes sit in `NotificationSettings`, `SigningDialog` and the search facets |
| Radio Button | F | C | in `SaveSearch`, `TimedTaskItem` and `AccountPage`, not in a form renderer |
| Fieldset | F | C | in `FederatedSearchBlock`, `SaveSearch` and `TimedTaskItem` |
| Number Input | X | F | `fieldConfigs.input: number` in `SchemaField`; `number` in the known types of `FormBlock` and `IntakeFormBlock` |
| Code, Code Block, Separator, Image | X | T, unstyled | markdown emits bare `<code>`, `<pre>`, `<hr>` and `<img>` (DOMPurify keeps `img`); `MarkdownBlock`'s class map covers only headings, paragraph, link, lists and blockquote |
| Contact Timeline | X | C | `TimelineList.vue` renders the case timeline as an ordered list |
| Strong | T (assumed) | T, unstyled | bare `<strong>`; `@utrecht/emphasis-css` (`utrecht-emphasis--strong`) is not used |

Corrected totals: W 3, T 17, F 11, S 11, C 21, X 38 (the matrix said W 3, T 13, F 13, S 11, C 17, X 44).

Also found:

- The site budget is 412 KiB, not 400 (`webpack.site.js`, raised twice), and the entry sat about 1.3 KiB under it at the last raise. CSS travels inside JavaScript (`style-loader`). Every new widget must load on demand.
- The library's dashboard registry already uses `table`, `tabs` and `map`. A new widget under a natural name could collide with a non-public dashboard widget and make it look public. New keys therefore carry an `nl` prefix (`nlTable`, `nlTabs`).
- `AttachedActionResolver` and `ActionConfigNormaliser` keep no `summary` or `audiences` on an action. The dossiq lane needs both for the "Wat wilt u regelen?" tiles on its signed-out home (`site-resident-portal-design` D5, dossiq PR #3249).

## What changes

- One widget per component, or a deliberate reason why not. `design.md` holds the table of all 101: widget key, palette group, editable fields, CSS source and wave.
- The palette gets six groups (Inhoud, Navigatie, Formulieren, Terugkoppeling, Mijn omgeving, Opmaak), a search field, and drag from the palette onto the grid. Click or Enter still adds a widget, for keyboard users.
- Form-field components are placed inside a `form` widget, not on the grid.
- A widget declares its own metadata (group, label, fields, default size, NL Design System name) in a small file beside it. The editor reads the metadata; the site reads only a loader. So no widget reaches the site entry.
- Components NL Design System publishes no CSS for get our own implementation on existing Utrecht tokens. The design lists them.
- Actions gain `summary` and `audiences`, read by a "start tiles" widget.
- Six waves. Each is one PR.

## Decisions for Ruben

Components proposed as NOT standalone widgets, with the reason:

| Component(s) | Proposal | Reason |
|---|---|---|
| Root, Page Body, Page Layout, Page Header, Page Footer, Navigation Bar, Logo, Skip Link, Breadcrumb Navigation | shell, not a widget | every page has exactly one; the portal sets them (#953 header shape, footer, regions) |
| Grid | not a widget | it is the editor's grid itself |
| Strong, Subscript, Superscript, Mark, Code | inline, inside the text widget | a word in a sentence, never a block on a page |
| Heading 1 to 6 | one Heading widget with a level | six widgets that differ in one number crowd the palette |
| Form Field, Form Field Label, Form Field Description, Form Field Error Message, Form Field Label Suffix, Form Field Status | parts of every form field | they only exist around an input |
| Form Navigation, Form Summary | parts of a multi-step form | `site-multi-step-forms` draws them |
| Data Badge, Dot Badge, Number Badge, Status Badge, Spinner, Icon, Avatar, Page Number Navigation | parts of other widgets | a badge, a spinner or a page number alone says nothing; the avatar belongs to the record switcher |
| Alert Dialog, Modal Dialog | variants of the Dialog widget | same behaviour, different role |
| Date Input | replaced by Date Input Group | NL Design System form guidance asks day, month, year |
| Password Input | not offered | a public site form never asks for a password |
| Color Sample | not offered | a style-guide component, no visitor use |

Count from the table in `design.md` D1: 48 components become standalone widgets (34 new `nl` keys plus `card`, `cardGrid`, `markdown` and `siteNavigation`), 17 become field types inside the form widget, 18 are parts of other widgets, 5 are inline in the text widget, 10 are shell and 3 are not offered. Three more Mijn omgeving widgets (`nlInbox`, `nlStartTiles`, `nlRecordSwitcher`) have no NL Design System row of their own. Ruben may move any row.

## Not in this change

- The mijn omgeving components themselves: `site-mijn-omgeving-components`. This change makes them placeable.
- The form-field behaviour (error summary, date group, steps): `site-multi-step-forms`. This change makes the fields placeable inside a form widget.
- Editing header and footer regions in the designer. The designer still edits the main region only (`site-navigation-block`, "Not changed").
- Dark mode.

## Mockups cited

`LearniqHome.dc.html` and `DossiqHome.dc.html` (hero, card grid, start tiles, link list, news on a signed-out home); the ten signed-in mockups for the Mijn omgeving group.

## Affected projects

- portaliq: `src/site/components/WidgetGrid.vue`, `src/lib/pageWidgetCatalogue.js`, `src/editor/widgetForms.js`, `src/editor/WidgetPalettePanel.vue`, `src/editor/PageGridEditor.vue`, new `src/site/widgets/<key>/` folders, `lib/Contribution/ActionConfigNormaliser.php` and `AttachedActionResolver.php` (`summary`, `audiences`), `package.json` (Utrecht and candidate CSS packages per wave).
- `@conduction/nextcloud-vue`: none required. Library blocks stay as they are.
- dossiq: declares `summary` and `audiences` on its start actions (its own change).

## Amendment, 2026-10-05: Woo capability programme, wave 1

Row 6.13 of the Woo capability register, "The portal reads in Dutch and in at least one other
language", rated partial (production), is added to this change. No Ruben decision governs it.
Re-checked on `development` at ca591037: this change is open (T8b to T12 open), so the amendment
reopens T8a, adds REQ-SNW-013 and tasks T8c and T8d, and changes nothing else.

**Why.** T8a is ticked and not true in code: `nlLanguageNav` renders only when it receives more than
one locale, and nothing hands it any. `WidgetGrid.vue` never passes `locales` and `propsFor()` has no
branch for the widget. `contentApi.js` sends no locale, so even a working switch would change nothing
on the page. PR #1196 fixes both and was open, conflicting and red on 2026-10-05.

**What it adds.** The shell hands the portal's locales to the switch, every content read carries the
chosen locale, links keep it, and a page without a translation says it is shown in the default
language.
