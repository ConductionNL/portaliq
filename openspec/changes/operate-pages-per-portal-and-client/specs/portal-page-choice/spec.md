---
status: proposed
---

# Spec: portal-page-choice

## Purpose

An organisation chooses which contributed pages each of its portals shows,
and a clerk chooses which pages and records one client sees. From pipelinq
matrix row `portal-menu-choice`, owned by portaliq.

## ADDED Requirements

### Requirement: A portal shows the pages its administrator chose, in the chosen order (REQ-PGC-001)

When a portal carries a navigation choice for the subject's audience, the
contributions answer for that portal SHALL leave out the pages marked hidden
and SHALL order the listed pages as listed, with pages not in the list after
them. A portal without a choice SHALL answer as before.

#### Scenario: The quotes page is left out of one portal
- **GIVEN** portal `open-tilburg` with pipelinq's quotes page hidden for the `client` audience
- **WHEN** a client signs in to `open-tilburg` and the portal loads its contributions
- **THEN** the menu shows no quotes page, and the same client on another portal of the organisation without that choice still sees it

#### Scenario: A newly installed app stays visible
- **GIVEN** the same portal choice, made before an app that contributes a "Documents" page was installed
- **WHEN** the client loads the portal
- **THEN** "Documents" is in the menu, after the pages the choice lists

### Requirement: A client sees only the pages and records left to them (REQ-PGC-002)

A portal account MAY carry a staff-set list of hidden pages. For that
account the contribution aggregate SHALL leave out those pages and every
collection that no remaining page shows, so every collection, object and
action route refuses those collections for that account as it refuses an
undeclared one. The account holder MUST NOT be able to change the list.

#### Scenario: One business client does not see its invoices
- **GIVEN** the account of "Bakkerij De Kroon B.V." with pipelinq's invoices page hidden
- **WHEN** the bakery's user opens the portal and then calls the invoices collection route directly
- **THEN** the menu has no invoices page and the collection route refuses the request, while another client's account still sees and reads its invoices
- @e2e exclude the direct API call is pinned by ContributionControllerTest; the menu half is covered by tests/e2e/operate-pages-per-portal-and-client.spec.ts

#### Scenario: A client cannot unhide a page
- **GIVEN** the same account
- **WHEN** its user sends `hiddenPages: []` through the self-service account update
- **THEN** the list is unchanged
- @e2e exclude whitelist seam; pinned by the self-service PATCH test

### Requirement: The administrator makes the portal choice on the portal's page (REQ-PGC-003)

The portal detail page in the portaliq admin SHALL list, per audience, the
pages the installed apps contribute, with a control to show or hide each and
to move it up or down, and SHALL say that hiding a page on a portal is not
access control.

#### Scenario: An administrator reorders the menu
- **GIVEN** an administrator on the detail page of portal `open-tilburg`
- **WHEN** they move "My cases" above "Invoices" for the `client` audience and save
- **THEN** a client's menu on `open-tilburg` lists "My cases" before "Invoices"
