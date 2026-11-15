## ADDED Requirements

### Requirement: Mijn omgeving components MUST use the Den Haag CSS on our own markup and load on demand (REQ-SMO-001)

The case card, process steps, action row, data badge, file item, contact timeline and side navigation MUST render the class structure of the matching `@gemeente-denhaag/*` package and import only that package's `dist/index.css`. The package versions MUST equal the versions thematiq's Den Haag token mapping pins. No site chunk MAY contain a JavaScript module of these packages or of `react`. Each component MUST load on demand, and `npm run build:site` MUST stay within the site budget. Portaliq MUST NOT declare any `--denhaag-*` or `--nl-data-badge-*` value.

#### Scenario: The entry does not grow
- GIVEN the site built with every mijn omgeving component
- WHEN the build reports its entrypoint
- THEN the entrypoint is within `maxEntrypointSize` of `webpack.site.js`
- AND no chunk contains a module under `node_modules/react`

#### Scenario: A Den Haag portal paints its case card
- GIVEN a portal on the `denhaag` set with thematiq's bridge linked
- WHEN a resident opens "Mijn zaken"
- THEN each case card's computed background and title colour come from the bridge, not the initial value

### Requirement: A case card MUST show what a resident needs to decide whether to open it (REQ-SMO-002)

A case card MUST be one link whose name is the case title. It MUST show the case type's name when known, the status in words, and the case reference. When the collection supplies them it MUST also show the step position ("Stap 2 van 4"), the answer date and whose turn it is. Colour MUST NOT be the only carrier of the status.

#### Scenario: A running Woo request
- GIVEN a case "Woo-verzoek bomenkap Lindelaan", status "In behandeling", reference "2026-0003", step 2 of 4, answer by 30 October, the resident's turn
- WHEN the card renders, as in `DossiqOverview.dc.html`
- THEN it reads "In behandeling", "Zaak 2026-0003", "Stap 2 van 4", "Antwoord uiterlijk 30 oktober" and "U bent aan zet"

#### Scenario: A case without progress data
- GIVEN a `cases` collection without `steps`, `dueField` or `turnField`
- WHEN its cards render
- THEN each shows title, status and reference, and no empty progress bar

### Requirement: Process steps MUST say where a case stands, in order (REQ-SMO-003)

The steps block MUST render the steps provider's answer as an ordered list in the order given. The current step MUST carry `aria-current="step"`. A done step MUST be announced as done and MAY show its date. A step's description MUST show under its label.

#### Scenario: The case page of `DossiqCase.dc.html`
- GIVEN the provider answers Ontvangen (done, 2 October), In behandeling (current), Besluit (todo), Afgerond (todo)
- WHEN the steps block renders
- THEN "In behandeling" is the current step and "Ontvangen" is announced as done

### Requirement: Tasks and messages MUST render as action rows with text badges (REQ-SMO-004)

A task or message row MUST be a list item with one link that names the row. A deadline MUST show as a badge with a date in words ("Voor 12 oktober"), or as a count of days from seven days out ("Nog 7 dagen"). An unread message MUST carry the badge "Nieuw". Badges MUST be text and MUST be read as part of the link.

#### Scenario: An unread message
- GIVEN an unread message "Wij hebben een vraag over uw Woo-verzoek"
- WHEN the inbox block renders
- THEN the row carries "Nieuw" and a screen reader hears it with the link name

### Requirement: A case's documents and history MUST render as file items and a contact timeline (REQ-SMO-005)

The documents block MUST list each document as a link that downloads it, with its name, its type and size in words ("PDF, 84 kB"), and who added it and when. The timeline block MUST list events newest first, each with its date and time and one sentence. Both MUST read the collection's existing `documents` and `timeline` providers.

#### Scenario: The receipt on the case page
- GIVEN the documents provider returns "Ontvangstbevestiging", from the municipality, 2 October 2026, PDF, 84 kB
- WHEN the documents block renders
- THEN the row reads "Ontvangstbevestiging" and "Van de gemeente, 2 oktober 2026. PDF, 84 kB"

### Requirement: The resident menu MUST show icons and counts in groups (REQ-SMO-006)

The resident menu MUST render each page's declared `icon` before its label, decorative to assistive technology. It MUST keep its counts, read as text ("1 nieuw"). It MUST group pages by `group` and leave out pages that declare `menu: false`.

#### Scenario: The guardian's menu in `Main.dc.html`
- GIVEN pages Overzicht, Berichten and Agenda in group "top", and per-child pages for Vera
- WHEN the menu renders
- THEN "Berichten" shows its icon and the count 3
- AND Vera's pages sit under a heading "Vera"

### Requirement: Mijn MUST open on what the resident still has to do (REQ-SMO-007)

`/mijn` MUST render a home instead of redirecting to the first menu entry. It MUST start with a greeting and, when anything is open, "Dit moet u nog doen", listing open portal tasks and the rows of every `tasks` block on a home page, by deadline. It MUST then render every page a contribution marks `home: true`. Without any home page it MUST show the running cases and the newest messages. It MUST NOT be blank: with nothing to show it MUST show an empty state.

#### Scenario: Sanne's overview in `DossiqOverview.dc.html`
- GIVEN dossiq marks `overzicht` as `home: true` with a `tasks` block on `vragenAanU`
- AND one open question with deadline 12 October
- WHEN Sanne opens `/mijn`
- THEN the first section is "Dit moet u nog doen" with "Beantwoord onze vraag over uw Woo-verzoek" and "Voor 12 oktober"

#### Scenario: A portal with nothing to do
- GIVEN a resident with no tasks, cases or messages
- WHEN they open `/mijn`
- THEN the page shows the greeting and an empty state that says there is nothing to do

### Requirement: The resident MUST see and switch for whom they act (REQ-SMO-008)

While a resident acts for someone else, every signed-in page MUST show a bar that names that party and offers a link back to acting for themselves. A page with `records` MUST show a switcher over the collection's rows as a radio group, with initials, a title and a subtitle. The chosen row MUST be part of the route. Switching MUST move focus to the page heading.

#### Scenario: Linda acts for her father, `DossiqPhone.dc.html`
- GIVEN Linda Bakker holds an active mandate for H. Bakker and has chosen it
- WHEN any signed-in page renders
- THEN a bar reads "U regelt nu zaken voor uw vader, H. Bakker" with "Wissel naar uzelf"

#### Scenario: A guardian switches child, `Main.dc.html`
- GIVEN the guardian overview declares `records: parentChildren` and the guardian has Vera and Sami
- WHEN she picks "Sami"
- THEN the route names Sami's record and every block shows Sami's data

### Requirement: Loading and empty states MUST say what is happening (REQ-SMO-009)

While a block loads, it MUST show a skeleton of its own shape, hidden from assistive technology, and one status message "Bezig met laden". A block with no rows MUST show an empty state that names what is empty and, when the block has one, the action to take. A list's empty state MUST NOT be a bare dash or an empty table.

#### Scenario: No messages yet
- GIVEN an inbox block with no messages
- WHEN it renders
- THEN it reads "U heeft nog geen berichten." and shows no empty list

### Requirement: Opening a record MUST land on its record page with that record chosen (REQ-SMO-010)

When a link opens a record of a collection (a notice, a message, a task, a case card), the site MUST also match pages whose `record` or `records` names that collection, not only pages with a `collection`, `detail` or `citizenCase` block on it. A matched record page MUST open on the route of that record, so its blocks show that record. A page with a list block on the collection MUST keep precedence, as today.

#### Scenario: A message about a case opens the case page
- GIVEN dossiq's case page `mijnZaken` is a record page on `mijnZaken` with `menu: false`
- AND a message links to case 2026-0003
- WHEN the resident follows the link
- THEN the case page opens with case 2026-0003 chosen
