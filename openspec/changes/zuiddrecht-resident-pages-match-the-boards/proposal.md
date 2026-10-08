---
kind: code
---

## Why

The signed-in pages of the Zuiddrecht example site (`occ portaliq:example-site:install zuiddrecht`,
the resident of `occ portaliq:example-resident:install zuiddrecht`) are rendered by the generic
Mijn omgeving blocks, and the Mijn Zuiddrecht boards draw several of them differently. Measured
on the demo instance (:8097, 6 October 2026): the case cards are Den Haag folder cards with the
step line above a 6px bar and a green status badge, where the board draws a white card with the
number left, a pill tag right (reading "Wacht op u" when the resident must act), a blue underlined
title, an 8px bar and one line "Stap 1 van 5 · klaar uiterlijk 1 november" under it; the open
question is an action row with a date badge, where the board draws a warning-toned card with a
bold title, the case line and a primary "Document toevoegen"; the newest messages are action rows
with "Nieuw" badges and chevrons, where the board lists a title and "vandaag"; the resident menu
has the site's own groups, where the board groups Overzicht and Berichten under Mijn Zuiddrecht;
Mijn zaken draws folder cards where the board draws rows with "Uiterlijk klaar op"; the case page
opens on "Uw zaak" as a heading with the title under it, shows Documenten and Wat er is gebeurd
twice (the documents block and the case screen, the timeline block and the detail card), and has
no "Gegevens" heading or a breadcrumb for Mijn zaken.

The site serves every portal, so none of this can be the new default. Each difference is a key a
contribution or a portal declares; a page or portal that declares none renders as before.

## What Changes

- **Block keys a contribution may declare** (server normalisers keep them only when well
  formed): `cases` with `display: compact`, `showAll: true` and `yourTurn: [values]`; a highlight
  `tasks` block with `tone: warning|info` and `dueInLine: true`; `inbox` with `display: list`;
  `documents` with `upload: true`; `detail` with `label` and `timeline: false`; `citizenCase` with
  `display: actions`; a page `record` with `heading: record` and `under: cases`.
- **Portal keys**: `residentMenu.groups` (the menu in the portal's own groups, by section name
  or `app:page`; an item not named keeps its place, so nothing becomes unreachable) and
  `myCases.display: rows`. Portal schema 0.12.0, register 0.67.0. The Zuiddrecht example site
  declares both.
- **The components**: CaseCard `display` compact and row, an info tone on the data badge,
  CasesBlock's "Alle zaken" beside its heading, TasksBlock's highlight tone and due line,
  InboxBlock's plain list, DocumentsBlock's upload button, DetailCard's heading and timeline
  switch, CitizenCase's actions display (the closed-window sentence as a green notice, no status
  block, no documents section), ContributionPage's record eyebrow and h1, the breadcrumb's
  Mijn zaken crumb, MyCasesPage's rows and tab roles, the resident menu's Overzicht item.
- **Every moved thing keeps a route**: Bezwaar maken and Klacht indienen stay on the overview;
  Mijn wijziging opslaan and Deze aanvraag intrekken stay on the case page under Stukken; Toegang
  tot zaken and Melding indienen stay in the menu (appended in their own groups when the portal's
  layout does not name them). `tests/zuiddrecht-resident-boards.spec.mjs` proves each.
- **Every other portal renders unchanged**: the same spec renders the blocks without the keys
  and holds them to the markup they had.

**Out of scope, named for dossiq** (`lib/Portal/PortalPages.php`, `CitizenManifest.php`): the
keys above on its blocks and pages, `valueLabels.portalTurn.applicant` reading "Wacht op u", a
lookup of the case title for the open question's line. The case page's section order stays
dossiq's (steps, documents, timeline, facts), an open decision.

## Capabilities

### Modified Capabilities
- `portal-contribution-contract`: the board keys on blocks and pages.
- `site-resident-menu`: a portal's own menu groups and the cases page display.

## Impact
- `lib/Contribution/BoardKeys.php` (new), `ListBlockNormaliser.php`, `SchoolBlockKeys.php`,
  `PortalBlockResolver.php`, `RecordBlockNormaliser.php`, `lib/Settings/portaliq_register.json`,
  `lib/Settings/sites/zuiddrecht.json`.
- `src/site/components/mijn/*`, `src/site/components/e/CitizenCase.vue`,
  `src/site/components/collections/DetailCard.vue`, `src/site/pages/collections/ContributionPage.vue`,
  `src/site/pages/e/MyCasesPage.vue`, `src/site/lib/residentMenu.js`, `src/site/lib/accountArea.js`,
  `src/site/pages/registry.js`, `src/site/App.vue`, strings.
