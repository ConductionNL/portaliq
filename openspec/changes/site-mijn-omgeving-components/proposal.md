# Proposal: site-mijn-omgeving-components

## Why

Ruben approved twelve site mockups on 2026-10-02 (`canvas/project/`). Ten of them show a signed-in area ("mijn omgeving"). They are built from about a dozen components that the site either lacks or draws in its own markup. Ruben decided that the missing ones adopt the `@gemeente-denhaag/*` CSS on our own Vue markup.

What each mockup needs, against what `development` (b150def5) ships:

| Component | Mockups | On `development` | This change |
|---|---|---|---|
| Case card ("Stap 2 van 4", deadline, whose turn) | `DossiqOverview`, `DossiqBusiness`, `DossiqPhone` | "Mijn zaken" is a table with tabs (`MyCasesPage.vue`) | NEW |
| Process steps (status) | `DossiqCase`, `LearniqAssessor` | none | NEW |
| Action row for tasks and messages, "Nieuw" badge, deadline badge | `Main`, `DossiqOverview`, `DossiqBusiness`, `DossiqPhone`, `LearniqPupil`, `LearniqTrainer`, `LearniqAssessor`, `LearniqAbsence` | `TasksPage.vue`, `MessagesPage.vue`, `ItemList.vue` in own markup | NEW look over existing data |
| File item | `DossiqCase` | a list of buttons in `CitizenCase.vue` | NEW look over existing data |
| Contact timeline | `DossiqCase` | `TimelineList.vue`, an ordered list | NEW look over existing data |
| Description list | `DossiqCase`, `DossiqWoo`, `DossiqPhone`, `LearniqTrainer` | `DetailCard.vue` already a `<dl>` | restyle |
| Side navigation with icons and counts | `Main`, `DossiqOverview`, `DossiqBusiness`, `LearniqTrainer`, `LearniqAssessor` | `ResidentMenu.vue` with counts, no icons | icons, Den Haag look |
| Figure tiles | `Main` | `KpiCards.vue` | restyle |
| Quick action tiles | `Main`, `DossiqOverview`, `DossiqPhone`, `LearniqPupil` | `cta` blocks render as buttons | NEW look |
| "For whom" switcher (child, self, company, mandate giver) | `Main`, `DossiqBusiness` | `ActingForSwitcher.vue`, a select | NEW |
| Acting-for bar | `DossiqPhone`, `DossiqBusiness` | the same select in the header | NEW |
| Overview page, "Dit moet u nog doen" first | `Main`, `DossiqOverview`, `DossiqBusiness`, `DossiqPhone`, `LearniqPupil`, `LearniqTrainer` | `/mijn` redirects to the first menu entry; no overview | NEW |
| Empty states | all signed-in mockups by implication | bare paragraphs ("Nothing planned from today.") | NEW |
| Skeleton loading | none drawn; NL Portal uses it | text "…" and `aria-busy` | NEW |

Verified on npm (2026-10-02): each `@gemeente-denhaag/<component>` package ships its CSS as `dist/index.css` beside its React code (EUPL-1.2). The CSS has almost no fallbacks: of 307 `var()` reads across the families this change needs, 254 carry none, and `@gemeente-denhaag/card` 5.1.4 alone reads 74 bare `--denhaag-*` references (thematiq#892). Without tokens the components render unstyled. `@gemeente-denhaag/data-badge` 2.2.2 reads `--nl-data-badge-*`, not `--denhaag-*`. The bundle file `@gemeente-denhaag/components-css` 4.1.1 is one 245,559-byte file and holds no data badge CSS, so it is not used.

### The keys learniq declares (coordinator note, 2026-10-02)

The learniq lane's specs (learniq PR #1641, `site-guardian-portal-design` and siblings) declare keys portaliq does not accept. Checked against `development` (b150def5):

| Key | Where | On `development` |
|---|---|---|
| `menu.group` | page | `PortalPageResolver` keeps `id`, `label`, `icon`, `record`, `blocks`. PR #1097 (open) adds `group` on a contributed page. |
| `menu.hidden`, `menu.perRecord` | page | not accepted |
| `records` | page | not accepted; `record` (one record page) is |
| `tasks`, `inbox` | block type | `PortalBlockResolver::BLOCK_TYPES` = collection, action, detail, richText, cta, citizenCase, kpi, calendar, news |
| `limit`, `sort` | `collection` block | not accepted (`news` has `limit`) |
| `range` | `calendar` block | not accepted; the block shows everything from today |
| `widget` | action `fieldConfigs` | dropped by `ActionConfigNormaliser`; specified in `site-multi-step-forms` (REQ-SMF-005) |
| a filter on the joined schema of `via` | collection | `PortalObjectReader::verifiedJoinTargets()` keeps join rows on `scopeField` and organisation only |

The dossiq lane (dossiq PR #3249, `site-resident-portal-design`) declares more, checked the same way:

| Key | Where | On `development` |
|---|---|---|
| `menu: false` | page | not accepted; `buildNav` lists every page |
| `steps: { label, provider: caseSteps }` | `cases` collection | not accepted; `timeline.provider` and `documents.provider` are (`TimelineProviderMethod`, `DocumentsProviderMethod`) |
| block types `actionList`, `caseCards`, `messageList`, `processSteps`, `fileList`, `contactTimeline` | page | placeholders, none accepted |
| a home page ("portaliq's home slot") | page | not accepted; `/mijn` redirects to the first menu entry |

This change specifies all of these. The action keys (`steps`, `draft`, `confirmation`, `summary`, `audiences`) are in `site-multi-step-forms` and `site-nlds-widget-palette`. The mandate keys are a gap (see "Not in this change").

After alignment (learniq#1641 at 87e44aeb, dossiq#3249 at 9c7ccddcf) both lanes found more the mockups need. Checked on `development` and added: a `cta` to a page or route with the record preset and `{title}` in its label (`normaliseCtaBlock()` takes an action only); `range: day`; record scope, lookups and `excludeWhen` on `tasks` (the collection block already has scope and lookups); `recordField` on `inbox`; a switcher subtitle from a one-hop lookup; a `richText` filled from the record; cards with a progress figure; and opening a record on its record page (`navKeyFor` matches list blocks only).

### Names (lane pq decides; both app lanes follow)

| Final | Replaces |
|---|---|
| page `group` (string), from #1097 | learniq `menu.group` |
| page `menu: false` | learniq `menu.hidden`; dossiq `menu: false` stays |
| page `perRecord: <collectionId>` | learniq `menu.perRecord` |
| page `records: { collection, titleFields?, subtitleFields? }` | learniq `records: <collectionId>` (the bare string is accepted as `{ collection }`) |
| page `home: true` | dossiq "portaliq's home slot" |
| block `tasks` | dossiq `actionList`, learniq `tasks` |
| block `inbox` | dossiq `messageList`, learniq `inbox` |
| block `cases` | dossiq `caseCards` |
| block `steps` | dossiq `processSteps` |
| block `documents` | dossiq `fileList` |
| block `timeline` | dossiq `contactTimeline` |
| collection `steps: { label?, provider }` | dossiq, unchanged |

A record-page block is named after the collection key it reads (`steps`, `documents`, `timeline`). A list block is named after what the resident sees (`tasks`, `inbox`, `cases`).

### The case screen

Two of the three faults named in the brief are already handled:

- The case screen receives only declared fields: `citizen-case-shows-only-its-fields`, merged in #1108 on 2026-10-02. `CitizenCaseController` projects through `PortalFieldProjector`; `caseFieldNames()` lists only the writable set.
- Public labels and value labels for detail fields: `fieldConfigs.<field>.label` and `.valueLabels` on a collection, PR #1097 (open). The status line on "Mijn zaken" shows `statusPublicLabel`, else `statusLabel` (#1097, after `statusLabelField` in #1108).

Still open, and in this change: "Mijn zaken" names the case type. The uuid the brief saw under a case title was the status type's uuid (#1097 found that). No row shows the case type's name. `PortalCaseTypeCatalogue` and `CaseTypeReader` already read names from a collection's `caseTypeSource`.

## What changes

1. The Den Haag CSS of each component, from its own `@gemeente-denhaag/<component>` package at the version thematiq's mapping pins, imported by the component that uses it. Every component loads on demand. No React is bundled.
2. Vue components on that CSS: `CaseCard`, `ProcessSteps`, `ActionRow` (with `DataBadge`), `FileItem`, `ContactTimeline`, `DescriptionList`, figure tiles, quick action tiles, `RecordSwitcher`, `ActingForBar`, `EmptyState`, `Skeleton`. The side navigation gains icons and the Den Haag look.
3. `/mijn` opens the portal's home: the page a contribution marks `home: true`, under portaliq's own "Dit moet u nog doen". Without one, a default overview of tasks, running cases and new messages.
4. Contract keys, named in the table above: page `group`, `menu: false`, `perRecord`, `records`, `home`; blocks `tasks`, `inbox`, `cases`, `steps`, `documents`, `timeline`; `limit` and `sort` on `collection`; `range` on `calendar`; `steps`, `dueField` and `turnField` on a `cases` collection; `via.when` and `via.validUntilField`.
5. "Mijn zaken" shows the case type's name.
6. Tokens. Portaliq reads `--denhaag-*` and `--nl-data-badge-*` and ships no value for them. thematiq's `denhaag-component-tokens` (thematiq#892) writes them into `css/public-bridge.css`, which `site-links-the-theme-bridge` links. Portaliq pins the same package versions.

## Not in this change

- Managing mandates. `DossiqBusiness.dc.html` and `DossiqPhone.dc.html` show who may act for a company, "Iemand machtigen", "Intrekken", "Uitnodiging intrekken" and "Machtiging stoppen". Portaliq owns mandates (`portalMandate`: `subjectRef`, `organisation`, `onBehalfOf`, `label`, `caseTypes`, `reach`, `status`, `grantedBy`, `grantedAt`, `expiresAt`). `PortalMandateService` reads them and honours `expiresAt`; no screen lists, invites, revokes or sets an expiry, and `onBehalfOf` has no typed form (`kvk:<n>`, `subject:<ref>`) yet. That needs its own change. Proposed name: `site-mandates-the-represented-manage`. This change only draws the switcher and the bar over the mandates portaliq already resolves (REQ-CMC-004).
- Permission slips, hours approval, interim assessments and exam sign-off (`Main`, `LearniqTrainer`, `LearniqAssessor`). STATE.md lists them as features the mockups assume. The learniq lane scopes them.
- Avatars with photos. The switcher shows initials only.
- Dark mode. Still off (`site.php`).
- The page declarations themselves (which page holds which block). Those are the dossiq and learniq changes.

## Affected projects

- portaliq: `lib/Contribution/PortalPageResolver.php`, `PortalBlockResolver.php`, `RecordBlockNormaliser.php`, `CollectionConfigNormaliser.php`, a `StepsProviderMethod` beside `TimelineProviderMethod`, `lib/Service/PortalObjectReader.php`, `PortalCaseListReader.php`, `src/site/components/mijn/*` (new), `ResidentMenu.vue`, `MyCasesPage.vue`, `CitizenCase.vue`, `TimelineList.vue`, `DetailCard.vue`, `KpiCards.vue`, `TasksPage.vue`, `MessagesPage.vue`, `src/site/lib/accountArea.js`, `package.json` (`@gemeente-denhaag/card`, `process-steps`, `step-marker`, `action`, `file`, `contact-timeline`, `side-navigation`, `data-badge`).
- thematiq: `denhaag-component-tokens` (thematiq#892) feeds the tokens.
- learniq, dossiq: adopt the keys (their own changes).
