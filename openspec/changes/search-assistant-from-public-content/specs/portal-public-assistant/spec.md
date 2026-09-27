---
status: proposed
---

# Spec: portal-public-assistant

## Purpose

A visitor asks a question on the public site and gets a short answer from the
organisation's published content, with its sources, from an assistant that
says it is one, cannot act, and never sees anyone's own records. Portaliq
matrix row `dem-rm-chatbot`.

## ADDED Requirements

### Requirement: The assistant answers from published content, with sources (REQ-SAP-001)

The `assistant` widget SHALL send a visitor's question through the public
assistant channel and SHALL show an answer only with at least one source
link to a published page, glossary term or publication of that portal.

#### Scenario: A visitor asks about waste collection
- **GIVEN** a portal with a published page "Afvalkalender feestdagen" and the assistant enabled
- **WHEN** a visitor asks "Wanneer wordt het afval opgehaald met Koningsdag?"
- **THEN** the widget shows a short answer and, under "Sources", a link to "Afvalkalender feestdagen"
- e2e: `tests/e2e/search-assistant-from-public-content.spec.ts`

#### Scenario: No source, no answer
- **GIVEN** a question the portal's content does not cover
- **WHEN** a visitor asks it
- **THEN** the widget shows "I could not find this in our information. You can contact us." with a link to the contact page, and no generated answer
- e2e: `tests/e2e/search-assistant-from-public-content.spec.ts`

### Requirement: The assistant never carries a resident's identity or records (REQ-SAP-002)

The assistant route SHALL be public, SHALL refuse a request that carries a
portal bearer, and SHALL forward no session, subject reference, organisation
claim, address or visitor id. The source scope SHALL contain only published
pages, glossary terms and published publications of the resolved portal, and
a test SHALL fail if any other schema is added to it.

#### Scenario: A signed-in resident's token is refused
- **GIVEN** a request to the assistant route with a portal bearer
- **WHEN** it arrives
- **THEN** the response is 400 and nothing is forwarded
- @e2e exclude Server-side refusal; pinned by PublicAssistantControllerTest

#### Scenario: The scope cannot grow into personal data
- **GIVEN** a developer who adds `portalMessage` to the source scope
- **WHEN** the unit suite runs
- **THEN** `PublicSourceScopeTest` fails
- @e2e exclude Build-time pin

### Requirement: The visitor knows it is an AI (REQ-SAP-003)

The widget SHALL show "Answers come from an AI assistant and can be wrong.
Check the page it links to." before the first question, and "Do not type
personal details such as your citizen service number." next to the input.

#### Scenario: The disclosure is there before asking
- **GIVEN** a visitor who opens a page with the assistant
- **WHEN** the widget renders
- **THEN** both sentences are visible before anything is typed
- e2e: `tests/e2e/search-assistant-from-public-content.spec.ts`

### Requirement: Personal details are removed before forwarding (REQ-SAP-004)

The channel adapter SHALL replace a number that passes the citizen service
number eleven-test, an email address and a Dutch phone number with
`[removed]` before forwarding, and the widget SHALL tell the visitor "We
removed personal details from your question."

#### Scenario: A visitor types their citizen service number
- **GIVEN** a question containing a valid citizen service number
- **WHEN** it is sent
- **THEN** the text forwarded to hermiq contains `[removed]` in its place and the visitor sees the notice
- @e2e exclude Forwarded payload; pinned by PublicAssistantChannelTest

### Requirement: The assistant cannot act (REQ-SAP-005)

The public assistant SHALL use no tool and SHALL cause no write. A request to
do something SHALL be answered in words, with a link to a form page when one
is among the sources.

#### Scenario: A visitor asks it to file a request
- **GIVEN** the assistant enabled
- **WHEN** a visitor asks "Vraag een parkeervergunning voor me aan"
- **THEN** the answer explains where to apply and links the form page, and no object is created
- e2e: `tests/e2e/search-assistant-from-public-content.spec.ts`

### Requirement: The assistant is off until a portal turns it on (REQ-SAP-006)

The `assistant` widget SHALL render only when the portal's `assistant.enabled`
is true and hermiq's channel entry point is available. Otherwise the widget
SHALL not be offered in the page designer and SHALL not render.

#### Scenario: A portal without the setting
- **GIVEN** a portal with `assistant.enabled` false
- **WHEN** a page that places the widget is opened
- **THEN** no assistant is shown
- @e2e exclude Absence; pinned by WidgetGrid Vitest
