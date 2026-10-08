---
status: proposed
---

# Spec: report-pages

## Purpose

The screens that make a report without an account usable: a public form
and follow-up page for the reporter, and screens for the people who handle
reports and the custodian who may reveal who filed one. The backend is the
open change `a-report-without-an-account-and-a-custodian-who-may-reveal-it`.
Portaliq matrix rows `int-report-anonymous-file`, `int-report-two-way-thread`,
`int-report-custodian-reveal` and `sib-dossiq-13-33`.

## ADDED Requirements

### Requirement: A reporter files a report on a public page and keeps a code (REQ-IRP-001)

The public site SHALL offer a `report` widget that runs the portal's own
challenge, files through `POST /portal/api/reports`, and shows the returned
receipt code once with the line "Keep this code. We cannot send it to you
again." The widget SHALL NOT write the code to the URL, browser storage, the
page title or a traffic event.

#### Scenario: A reporter files without an account
- **GIVEN** a visitor on a public page that holds the report widget, not signed in
- **WHEN** they describe what happened, leave the contact block empty and press "Send report"
- **THEN** the page shows a receipt code and "Keep this code. We cannot send it to you again."
- e2e: `tests/e2e/intake-report-pages.spec.ts`

#### Scenario: The code does not leak into the address bar
- **GIVEN** a reporter who has just received a receipt code
- **WHEN** the page URL, `localStorage` and `sessionStorage` are read
- **THEN** none of them contains the code
- e2e: `tests/e2e/intake-report-pages.spec.ts`

### Requirement: A reporter follows up with the code alone (REQ-IRP-002)

The public site SHALL offer a `reportThread` widget where a reporter types a
receipt code, reads the messages the handler made visible to them and the
acknowledgement and feedback terms, and sends an answer. A wrong code and an
unknown code SHALL get the same message.

#### Scenario: A reporter reads the handler's question and answers it
- **GIVEN** a report whose handler wrote a message visible to the reporter
- **WHEN** the reporter enters the receipt code on the follow-up page
- **THEN** they see the message and the terms, type an answer and see it in the thread
- e2e: `tests/e2e/intake-report-pages.spec.ts`

#### Scenario: A wrong code says nothing about what exists
- **GIVEN** a visitor on the follow-up page
- **WHEN** they enter a code that opens no report
- **THEN** the page says "We could not open a report with this code." and nothing else
- e2e: `tests/e2e/intake-report-pages.spec.ts`

### Requirement: Report pages stay out of visitor statistics (REQ-IRP-003)

The report widgets SHALL send no traffic event. The page designer SHALL warn
"This page is not excluded from visitor statistics. Add it before you
publish." on a page that holds a report widget and whose path is not in the
portal's `traffic.excludedPaths`.

#### Scenario: An editor is warned before publishing
- **GIVEN** an editor placing the report widget on a page whose path is not excluded
- **WHEN** they open the page in the designer
- **THEN** the warning is shown
- e2e: `tests/e2e/intake-report-pages.spec.ts`

### Requirement: Handlers see reports through the projection only (REQ-IRP-004)

The Nextcloud app SHALL list reports on a "Reports of wrongdoing" page
through a new `GET /api/reports` that returns each report through
`ReportProjection`, with its terms. A report detail SHALL show the thread and
let the handler reply, internal by default, and ask for a reveal with a
motivation. No staff screen SHALL read the report schemas through a generic
OpenRegister index.

#### Scenario: A handler answers a reporter
- **GIVEN** a handler in the declared handler group and a new report
- **WHEN** they open "Reports of wrongdoing", open the report, tick "Visible to the reporter" and send a question
- **THEN** the question appears in the thread, and the reporter sees it on the follow-up page
- e2e: `tests/e2e/intake-report-pages.spec.ts`

#### Scenario: The report list never carries the contact
- **GIVEN** a report whose reporter left an email address
- **WHEN** a handler opens the list and the detail
- **THEN** no name, email address or phone number of the reporter is shown or returned
- @e2e exclude Absence in every response body; pinned by ReportControllerTest::testIndexNeverReturnsContact

### Requirement: Only declared groups may handle reports (REQ-IRP-005)

`GET /api/reports`, `GET /api/reports/{id}`, `POST /api/reports/{id}/messages`
and `POST /api/reports/{id}/reveal-requests` SHALL answer only a member of the
report declaration's `handlerGroup` or `custodianGroup`, and only the
custodian group when no handler group is declared. Every other signed-in user
SHALL get the same 404 as for a report that does not exist.

#### Scenario: A colleague outside the groups cannot read a report
- **GIVEN** a signed-in Nextcloud user in neither the handler nor the custodian group
- **WHEN** they call `GET /api/reports/{id}` for an existing report
- **THEN** the response is 404, the same as for an unknown id
- @e2e exclude Server-side authorization; pinned by ReportControllerTest::testNonHandlerGets404

### Requirement: The custodian decides a reveal on a screen of their own (REQ-IRP-006)

The Nextcloud app SHALL offer a "Reveal requests" page that lists the pending
requests for the case types whose custodian group the user is in, each with
the motivation, and lets the custodian allow or refuse with a reason. An
allowed reveal SHALL show the contact details once, in the decision's
confirmation, and SHALL NOT show them anywhere else afterwards.

#### Scenario: A custodian allows a motivated reveal
- **GIVEN** a custodian and a pending request with the motivation "The reporter is the only witness to the incident"
- **WHEN** they choose "Allow", type a reason and confirm
- **THEN** the screen shows the contact the reporter gave, and the report detail afterwards shows who asked, who allowed it and why, without the contact
- e2e: `tests/e2e/intake-report-pages.spec.ts`

#### Scenario: An administrator who is not the custodian sees no requests
- **GIVEN** an instance administrator outside the custodian group
- **WHEN** they open "Reveal requests"
- **THEN** the list is empty and a decide call is refused
- @e2e exclude Authorization refusal; pinned by RevealServiceTest and ReportControllerTest
