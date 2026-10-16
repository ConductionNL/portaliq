---
status: proposed
---

# Citizen writes on their own case: the Woo dossier in Mijn zaken

## ADDED Requirements

### Requirement: The open question is answered on the case page (REQ-WDM-001)

`GET /portal/api/citizen/cases/{app}/{id}` SHALL answer `questions`: the rows of every collection of the case's contributing app that is declared with schema `aanvullingsverzoek` or carries the endpoint action the app names for answering, whose `case` is this case and whose `state` is `open`, scoped to the signed-in subject as that collection declares. Each row SHALL carry its `summary`, its `hersteltermijn` when set, and the endpoint action the app declared on that collection. The case page SHALL show each row above the status steps, in the warning-toned banner, with the action's form. Sending SHALL go through portaliq's existing endpoint action forward with the signed `X-Portal-Subject` assertion. After a 2xx answer the row SHALL read "Wij hebben uw antwoord. De behandelaar kijkt ernaar." and SHALL offer no second form. Portaliq SHALL keep no term state and SHALL NOT say the term runs again.

#### Scenario: The resident answers the question on the case
- **GIVEN** a Woo case 2026-0087 with an open question "Over welke speeltuinen en welke jaren gaat uw verzoek?"
- **WHEN** the resident opens the case and sends "De speeltuinen in Oosthaven, 2023 tot nu"
- **THEN** dossiq receives the answer through `beantwoordVraag`
- **AND** the banner reads "Wij hebben uw antwoord. De behandelaar kijkt ernaar."
- e2e: `tests/e2e/woo-dossier-in-my-cases.spec.ts` "the resident answers the question on the case"

#### Scenario: A question on another case is not shown
<!-- @e2e exclude Scope at the API seam; proven by CitizenCaseControllerTest::testQuestionsOfAnotherCaseAreNotAnswered. -->
- **GIVEN** a resident with two cases, and an open question on the other one
- **WHEN** she opens the first case
- **THEN** `questions` is empty

#### Scenario: A refused answer says so
<!-- @e2e exclude Error mapping; proven by CitizenCaseQuestionTest (vitest), which renders a 404 from the forward. -->
- **GIVEN** a question the handler closed a moment ago
- **WHEN** the resident sends an answer
- **THEN** the page says "Deze vraag is al afgehandeld." and reloads the case

### Requirement: The case shows the sentence the case app wrote about the term (REQ-WDM-002)

`portalCase` SHALL declare `termNote` (string, at most 200 characters, plain text). The case page SHALL show it under the row "Uiterlijk klaar op". When `legalDecisionDate` is empty and `termNote` is set, the page SHALL show `termNote` in that row's place. Portaliq SHALL render it as text, never as markup, and SHALL add no words to it. An empty or absent `termNote` SHALL show nothing.

#### Scenario: A paused term shows the case app's sentence instead of a date
- **GIVEN** a Woo case with no `legalDecisionDate` and `termNote` "De termijn staat stil sinds 6 oktober 2026. Hij loopt weer zodra wij uw antwoord hebben."
- **WHEN** the resident opens the case
- **THEN** the Gegevens show that sentence where the decision date would be
- e2e: `tests/e2e/woo-dossier-in-my-cases.spec.ts` "a paused term shows the sentence"

#### Scenario: Markup in the sentence stays text
<!-- @e2e exclude Escaping; proven by casePage.spec.js termNoteRows, which renders "<b>x</b>" as literal text. -->
- **GIVEN** a `termNote` that contains `<b>`
- **WHEN** the case page renders it
- **THEN** the angle brackets show as text

### Requirement: The case links to where its result is public (REQ-WDM-003)

`portalCase` SHALL declare `resultLink` (object with `label`, string, at most 80 characters, and `url`, string, `format: uri`). The case page SHALL show it as a link under the status steps when `url` uses `https` and its host is one of the Nextcloud instance's `trusted_domains`, the hosts the publication link of the case app is built on. Any other `resultLink` SHALL not be shown, and portaliq SHALL log it once per case at warning level. An empty `label` SHALL show "Bekijk het resultaat".

#### Scenario: The decision is public and the case links there
- **GIVEN** a Woo case with `resultLink` `{label: "Bekijk wat openbaar is gemaakt", url: "https://zuiddrecht.nl/woo/publicaties/2026-0087"}`
- **WHEN** the resident opens the case
- **THEN** the page shows the link "Bekijk wat openbaar is gemaakt"
- e2e: `tests/e2e/woo-dossier-in-my-cases.spec.ts` "the case links to the publication"

#### Scenario: A link to a foreign host is not shown
<!-- @e2e exclude Allow-list; proven by CitizenCaseResultLinkTest::testAForeignHostIsDropped. -->
- **GIVEN** a `resultLink` with url `http://example.com/x`
- **WHEN** the case page is built
- **THEN** no link is shown and one warning is logged
