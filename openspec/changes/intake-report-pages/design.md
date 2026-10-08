# Design: intake-report-pages

Read at portaliq `development` `eeda3fa`.

## What exists and what reaches it

- `appinfo/routes.php:356-362`: three anonymous routes
  (`POST /portal/api/reports`, `/portal/api/reports/thread`,
  `/portal/api/reports/thread/answer`) and four staff routes
  (`GET /api/reports/{id}`, `POST /api/reports/{id}/messages`,
  `POST /api/reports/{id}/reveal-requests`,
  `POST /api/reveal-requests/{id}/decide`).
- `lib/Controller/ReportController.php:134` `file()` takes `caseType`,
  `register`, `schema`, `report`, `contact` and the challenge fields
  (`nonce`, `solution`, `expiresAt`, `signature`), and returns the code once
  with `codeShownOnce: true` and `recoverable: false`. `thread()` (line 255)
  and `answer()` (line 285) take the code.
- `lib/Controller/PortalIdentityController.php:101`
  `challenge(string $surface = 'form')` issues the portal's own challenge;
  `file()` checks it with surface `report`.
- `show()` (line 310), `reply()` (line 342) and `requestReveal()` (line 380)
  check only that a Nextcloud user is signed in. `decideReveal()` (line 417)
  hands the decision to `RevealService::decide()`, which refuses anyone
  outside `portalReportDeclaration.custodianGroup`
  (`lib/Service/Reports/RevealService.php:161-166`, group check at line 136).
- There is no staff list route. `show()` needs an id nobody can find.
- `src/site/components/WidgetGrid.vue:159-179` `PUBLIC_WIDGETS` is the only
  gate for what renders at a public origin. No `report` widget is in it.
- `src/manifest.json` has no page for any of the four report schemas.

## D1. The reporter's pages are on the public site

The `/portal` SPA opens behind a sign-in. A reporter has no account, so both
reporter pages are `/site` widgets: `report` and `reportThread`, each a
single-file component under `src/site/components/`, added to
`PUBLIC_WIDGETS`. An editor places them on a page with the page designer
like any other public widget; `propsFor()` hands them the host's `portal`,
as it does for `form` (line 402).

The `report` widget's authored props are the case type reference
(`caseType`, `register`, `schema`), the fields to ask, and which of them are
contact fields. The contact block is visibly optional and says what giving
it means.

## D2. The receipt code lives in one place: the reporter's hands

On a 2xx from `file()`, the widget shows the code in a large, selectable
field with a copy button and the line "Keep this code. We cannot send it to
you again." It then drops the code from component state when the reporter
leaves the page. The code never goes into the URL, `localStorage`,
`sessionStorage`, the page title or a traffic event.

The `reportThread` widget asks for the code, calls `thread()` and holds the
code in component state for the answer call only. A wrong code gets the same
message as an unknown one, matching the backend's no-oracle answer.

## D3. The report pages stay out of analytics

The backend already refuses a traffic event on a path in the portal's
`traffic.excludedPaths` (`lib/Service/TrafficConfigResolver.php:379`,
`lib/Service/TrafficIngestService.php:225`). The page designer shows a
warning on a page that holds a `report` or `reportThread` widget and is not
in that list: "This page is not excluded from visitor statistics. Add it
before you publish." The widgets themselves send no traffic event.

## D4. Staff screens read through the projection, never the generic index

A generic OpenRegister index on `portalReport` would read the objects
directly and skip `ReportProjection`, the one function every answer passes
through. So the staff screens are custom pages over portaliq's own routes:

- New route `GET /api/reports`, `ReportController::index()`, returning
  `ReportProjection::one()` per report plus its terms from
  `ReportTermsService`, newest first, for the reports the caller may handle.
- New route `GET /api/reveal-requests`, `ReportController::revealRequests()`,
  returning the pending requests for the case types whose `custodianGroup`
  the caller is in, each with report subject, motivation, requester and
  time. Never the contact.
- `src/manifest.json` gains `WrongdoingReports` (`/wrongdoing-reports`,
  custom component `WrongdoingReportList`), `WrongdoingReportDetail`
  (`/wrongdoing-reports/:id`, custom `WrongdoingReportDetail`) and
  `RevealRequests` (`/reveal-requests`, custom `RevealRequestDesk`), and one
  menu entry "Reports of wrongdoing".

## D5. Who may handle a report is declared

`portalReportDeclaration` gains an optional `handlerGroup`. `index()`,
`show()`, `reply()` and `requestReveal()` answer only a member of that group
or of `custodianGroup`. When `handlerGroup` is not declared, only the
custodian group may handle. Everyone else gets the same 404 as for a report
that does not exist. This tightens routes the original change left open to
any signed-in user, and it is done here because a list screen makes those
routes easy to find.

## D6. A reveal is shown once, on the custodian's screen

`decideReveal()` returns the contact on an allowed reveal. The
`RevealRequestDesk` shows it in the decision's confirmation and keeps it in
component state only; it is not written to the object store of the SPA and
not shown on the report detail afterwards. The record of the reveal (who
asked, who allowed, why, when, which fields) is what the report detail shows.

## D7. The contact schema is checked against its own promise

`portalReporterContact` declares `authorization: {"read": ["authenticated"]}`
in `lib/Settings/portaliq_register.json`, while its description says nothing
in it is reachable except through an allowed reveal. Portaliq's own reader
runs with RBAC off (`lib/Service/PortalObjectReader.php:233-238`), so a
narrower declaration would not affect `RevealService`. Read literally, the
current declaration lets any signed-in Nextcloud user read the contact
through OpenRegister's own objects API. This is a code reading. Task T01
checks it on a live instance before any screen ships, and narrows the
declaration if it holds.

## Risks

- **An editor places the form on a tracked page.** D3 warns; the backend
  still refuses the event.
- **An OpenRegister administrator bypasses schema authorization.** D7
  narrows what a normal user can read; what an OpenRegister administrator
  can read is OpenRegister's rule and is recorded as a residual risk in the
  docs.
- **Existing declarations without `handlerGroup`.** D5 falls back to the
  custodian group, so reports stay handled, by fewer people.

## What this deliberately does not do

- It does not change how a report is stored, throttled or projected.
- It does not add attachments to the report form.
- It does not put the reporter pages in the signed-in portal.
