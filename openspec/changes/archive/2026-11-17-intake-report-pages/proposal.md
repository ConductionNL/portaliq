---
kind: code
depends_on: [a-report-without-an-account-and-a-custodian-who-may-reveal-it]
---

# Proposal: intake-report-pages

## Why

A person who wants to report wrongdoing without saying who they are cannot
do it on any page of the portal. The backend that accepts the report, keeps
the reporter apart from it, runs the two-way thread on a receipt code and
gates the reveal on a named custodian is built and tested at the API. No
page calls it. A custodian who gets a reveal request has no screen to answer
it on.

The rows, all in the portaliq matrix, all rated `no` with `built.state`
`built`:

- `int-report-anonymous-file`, "File an anonymous report of wrongdoing with
  no account, and keep a receipt code to follow it up." Its `built.note`,
  verbatim:

  > Every task in openspec/changes/a-report-without-an-account-and-a-custodian-who-may-reveal-it/tasks.md is checked, including e2e and PHPUnit, and the backend (receipt code, two-way thread, contact separation, reveal workflow) is genuinely complete and well-tested at the API level. But there is no page anywhere, not the portal SPA, not the public site, not the admin app, that a reporter or a citizen could actually open to use it. This is the strongest 'code, not capability' finding in this area.

- `int-report-two-way-thread`, "Exchange follow-up messages with the
  organisation about your anonymous report, using only the receipt code."
  Its `built.reachedOn`, verbatim:

  > nothing reaches it: no frontend page anywhere calls /portal/api/reports/thread or /portal/api/reports/thread/answer

- `int-report-custodian-reveal`, "Let a named custodian reveal a reporter's
  identity only for a motivated reason, with every reveal logged." Its
  `built.reachedOn`, verbatim:

  > nothing reaches it: not even a generic OpenRegister index page exists for portalReport/portalRevealRequest in src/manifest.json, so staff have no UI at all for this, not even the fallback generic-pages route

- `sib-dossiq-13-33`, "Report accepted without an account, unmasked only by
  a named custodian", the dossiq matrix row 13.33 mirrored here
  (`sourceId` `int-dossiq-13.33`). Its `built.note`, verbatim:

  > dossiq's self-rating of 'no' is confirmed, but for a stronger reason than dossiq could see from its own repo: portaliq did build the entire feature (schemas, services, both PHPUnit and an API-level Playwright spec), it is simply not wired to any page a reporter, citizen or custodian could open.

No competitor is rated `yes` on any of the four rows. On
`int-report-anonymous-file` two are `partial`. `xxllnc-pip`, verbatim:

> backend/perl-api/lib/Zaaksysteem/Controller/Form.pm:359 anonymous submission via preset client; backend/perl-api/root/tpl/zaak_v1/nl_NL/form/finish.tt:40 confirmation [reached on /aanvragen/<id>/onbekend; no receipt code to follow the report up]

`liferay-dxp`, verbatim:

> https://learn.liferay.com/w/dxp/low-code/objects/understanding-object-integrations/managing-guest-user-entries: guest users can create object entries with no account. A receipt code to follow the report up later is not documented. [was unknown]

The lane applied the stranded-backend rule: the open change shipped the
backend without the capability, so this change builds only the missing half.

## What changes

- **A report form on the public site.** A `report` widget for `/site` pages
  that files through `POST /portal/api/reports`, runs the portal's own
  challenge first, and shows the receipt code once, with the warning that it
  cannot be sent again.
- **A follow-up page on the public site.** A `reportThread` widget where the
  reporter types the receipt code, reads the handler's messages and the
  terms, and answers. The code is held in memory only, never in the URL or
  browser storage.
- **Screens for the people who handle reports.** In the Nextcloud app: a
  list of reports, one report with its thread, a reply that is internal
  unless the handler says otherwise, and a reveal request with a
  motivation.
- **A screen for the custodian.** Open reveal requests, each with the
  motivation, and allow or refuse with a reason. An allowed reveal shows the
  contact details once, on that screen.
- **Access to the handler screens is declared, not implied.** Today any
  signed-in Nextcloud user may call the staff read, reply and reveal-request
  routes. A handler group on the report declaration closes them to everyone
  else before a screen makes them easy to find.

## Rows this closes

| Matrix | Row id | Row name | Own rating | What is missing |
|---|---|---|---|---|
| portaliq | `int-report-anonymous-file` | File an anonymous report of wrongdoing with no account, and keep a receipt code to follow it up. | no | A public page with the report form and the receipt code. |
| portaliq | `int-report-two-way-thread` | Exchange follow-up messages with the organisation about your anonymous report, using only the receipt code. | no | A public page that opens the thread on a code and lets the reporter answer. |
| portaliq | `int-report-custodian-reveal` | Let a named custodian reveal a reporter's identity only for a motivated reason, with every reveal logged. | no | Staff screens for the handler's reveal request and the custodian's decision. |
| portaliq | `sib-dossiq-13-33` | Report accepted without an account, unmasked only by a named custodian | no | All three of the above, as seen from dossiq's row 13.33. |

## Existing work it builds on

- `a-report-without-an-account-and-a-custodian-who-may-reveal-it` (open,
  every task checked) shipped the backend: the schemas `portalReport`,
  `portalReporterContact`, `portalReportMessage`, `portalRevealRequest`;
  `ReportIntakeService`, `ReportThreadService`, `ReportTermsService`,
  `RevealService`, `ReportProjection`; `ReportController` and its seven
  routes. Its own tasks.md says: "Left for a follow-up: the reporting form
  and the thread page are reachable over the API and are not yet rendered by
  a portal page block." This change is that follow-up and does not redo the
  backend.
- `landing-page-provisioning` and `portal-page-designer` (spec): the public
  widget registry `PUBLIC_WIDGETS` in `src/site/components/WidgetGrid.vue`
  that the two new widgets join.
- `portal-identity-and-the-organisations-cases` (open): the challenge route
  `GET /portal/api/identity/challenge?surface=` the form reuses.
- `portal-traffic-analytics` (open): `traffic.excludedPaths`, which the
  report pages must be listed in.

## Out of scope

- Investigating the report. The case app runs the case.
- The external reporting channel at the Huis voor Klokkenluiders.
- Anonymising an attachment. Filinq owns anonymisation, and the form takes
  no attachment in this change.
- Any change to what the backend stores or how it throttles.

## Sibling halves

- **ConductionNL/dossiq** declares `portalReportDeclaration` on its case type
  and listens for `portal.report.revealed`, as the original change already
  states. This change adds one optional key to that declaration,
  `handlerGroup`; dossiq sets it when it declares the case type. Not written
  here.
