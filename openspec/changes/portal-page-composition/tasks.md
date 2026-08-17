# Tasks: portal-page-composition

## 0. What was measured before designing this

- [x] 0.1 The shell is markup. `App.vue` hard-codes `<header>`, the navigation and the footer; the footer's two bands are selected in CSS by `:first-of-type` / `:last-of-type:not(:only-of-type)`, so a third band is impossible and adding one restyles the others.
- [x] 0.2 The grid already exists. Every public widget carries `gridX`, `gridY`, `gridWidth`, `gridHeight` — the same geometry the admin dashboard and detail pages use. This change does NOT introduce a grid.
- [x] 0.3 `slot` is declared and read by nothing. Nine seeded widgets carry `"slot":"body"`; a search for a consumer returns nothing. The region hook is already in the data.
- [x] 0.4 An editor already exists. `CnDashboardGrid` + OpenBuild edit mode + the add-widget modal + per-type config forms drive admin pages today, drag/resize included.
- [x] 0.5 Prior art surveyed — Puck (register your own components with a field config; same-origin iframe preview; per-component permissions), GrapesJS (block manager, device manager, pluggable storage, plugin API), Plasmic (canvas renders your real production components in an isolated sandbox). All three converge on library + canvas + inspector + layer tree.

## 1. The region model

- [x] 1.1 Extend the page shape to `regions: { header, hero, main, aside, footer }`. **Done** — the public contract now carries `body.regions` and `body.unknownRegions` ALONGSIDE the flat `body.widgets`, not instead of it. Every existing consumer reads `widgets` (the built-in renderer, the Docusaurus plugin, the e2e grid check) and removing it to ship regions would break all three for a feature none of them use yet. The region list is CLOSED: a widget can be placed in one of five regions and nowhere else.
- [x] 1.2 Honour `slot` as the region key, and report an unknown region visibly. **Done.** `slot: "body"` — which nine seeded widgets carry and nothing has ever read — means `main`, so regions arrived with no data migration; verified on the live contract, which reports `regions: {main: 3}`. A slot matching no region is collected into `unknownRegions` **by name**: a widget assigned to a region that does not exist is an authoring mistake, and one that silently vanishes is debugged by guessing.
- [x] 1.3 Resolve regions portal-first, page-override, explicitly-empty meaning NONE. **Done**, and the implementation detail is the whole task: `array_key_exists`, never `isset` or `??`, both of which treat a present-but-empty value as absent and collapse "emptied" into "inherited". The emptied state is what lets one landing page drop the portal's hero without deleting it for every other page.
- [x] 1.4 Test all three states per region. **Done, for every region rather than a representative one, and checked against a deliberate break**: swapping the resolver to the naive `empty()` test fails exactly the emptied case ("empty was read as unset") and passes the other two, which is the failure mode the task predicted. A separate test pins the other half — a flat legacy list must leave unmentioned regions INHERITABLE, or shipping regions would strip the chrome from every existing page at once.

## 2. Retire the hard-coded shell

- [ ] 2.1 Express the current header, navigation and footer as the DEFAULT region templates, so today's portals render identically with no content change.
- [ ] 2.2 Replace the positional footer CSS with per-band configuration; a band's styling must not depend on its index.
- [ ] 2.3 Test: an existing portal renders byte-identically before and after the shell moves into data — a migration that changes every portal's appearance is not a migration.

## 3. The blocks the reference needs

- [ ] 3.1 `brandHeader` — logo, navigation, locale switcher, call-to-action.
- [ ] 3.2 `heroBand` — eyebrow, heading, supporting text, up to two buttons, optional illustration slot.
- [ ] 3.3 `iconCardGrid` — cards with an icon from the closed vocabulary (page content is authored input; raw SVG from an author is attacker-controlled markup).
- [ ] 3.4 `footerColumns` — link columns above a legal bar.
- [ ] 3.5 Every block takes its strings as props and no `t()`, per the public entry point's one rule.

## 4. The editor

- [ ] 4.1 Mount the REAL blocks on the canvas — the same components the public renderer uses, with the portal's theme applied.
- [ ] 4.2 Block library, grouped by category, each entry naming what it does rather than what it is called.
- [ ] 4.3 Inspector driven by each block's declared field configuration, not hand-written per block.
- [ ] 4.4 Layer tree over region → block, with selection synchronised both ways.
- [ ] 4.5 Breakpoint switcher that reflows the canvas exactly as the public page does.
- [ ] 4.6 Undo/redo across move, resize, insert, delete and field edits.
- [ ] 4.7 Test: what the canvas renders and what the public route renders are the same DOM for the same page — the property the whole editor rests on.

## 5. Guardrails

- [~] 5.1 Surface a skipped heading level as the author creates it. **Detected, but on the rendered page rather than in an editor** — there is no editor yet (§4), so the check lives in `tests/site-surfaces.spec.mjs`, which walks the visible outline across 12 page/width combinations and names the jump (`h1 → h3 at "Verhuizing doorgeven"`) rather than counting it. Only DOWNWARD jumps count; coming back up is how a document starts a new section, and flagging that would make the check cry wolf on every well-structured page. It is in the self-test too, so a run that cannot detect an injected `h4`-after-`h2` refuses to report a pass. **It found a real defect on its first run**: `/aanvragen` rendered `h1` then four `h3` cards, because `CnSiteCard` takes a `headingLevel` prop precisely to avoid this and that page's grid omitted it while the home page's sets it to 2. Fixed in the data. Moving this to authoring time is what §4 would add.
- [ ] 5.2 Warn on a block placed where its text fails contrast against the painted band behind it, naming the measured ratio — walking to the first ancestor that PAINTS a background, because comparing against the nearest named band produced a false failure in this codebase once already, and the "fix" for it made a working form invisible.
- [ ] 5.3 Keep the grid the only layout primitive: no absolute positioning, no per-block CSS.

## 6. The reference template

- [ ] 6.1 `conduction-docs` — white header with logo/nav/CTA, cobalt hero band with eyebrow, heading and two buttons, icon card grid, footer link columns above a legal bar.
- [ ] 6.2 Apply it to the Conduction client portal and compare against docs.conduction.nl structurally.
- [ ] 6.3 Record every part of the reference the region model CANNOT express, against this spec, rather than reaching for bespoke CSS. The template is the conformance test; a model that cannot express one real design will not express the next.
