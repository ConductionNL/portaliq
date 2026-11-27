# portal-notices Specification

## Purpose
An editor tells every visitor of a portal about maintenance or a warning,
for a set period, on the public site and in the signed-in portal. Portaliq
matrix row `dem-cl-maintenance-notice`.

## Requirements

### Requirement: A notice shows on every page during its window (REQ-OMN-001)

The public site and the signed-in portal SHALL render every published notice
for the portal whose window contains now and whose surfaces include that
application, above the page content. Outside its window a notice SHALL NOT
be sent by the server, and a notice whose `endsAt` has passed SHALL NOT be
rendered by the client.

#### Scenario: Visitors read about planned maintenance
- **GIVEN** a published warning "Saturday from 22:00 to 02:00 you cannot submit requests." for the site and the portal, running now
- **WHEN** a visitor opens any page of the public site, and a resident opens the signed-in portal
- **THEN** both see the warning above the page content
- e2e: `tests/e2e/operate-maintenance-notice.spec.ts`

#### Scenario: A notice disappears when its window ends
- **GIVEN** a notice whose `endsAt` has passed
- **WHEN** a visitor opens the site
- **THEN** the notice is not shown
- e2e: `tests/e2e/operate-maintenance-notice.spec.ts`

#### Scenario: A draft is not shown
- **GIVEN** a notice in `draft` with a window that contains now
- **WHEN** a visitor opens the site
- **THEN** the notice is not shown
- @e2e exclude Server filter; pinned by PortalNoticeReaderTest::testDraftIsNotActive

### Requirement: A visitor can close a notice for the visit (REQ-OMN-002)

Each notice SHALL have a "Close this notice" button. Closing SHALL hide that
notice for the rest of the browser session and SHALL NOT hide any other
notice. The notice SHALL NOT use `role="alert"`.

#### Scenario: A closed notice stays closed while browsing
- **GIVEN** a visitor who closed the maintenance notice
- **WHEN** they open another page of the same site
- **THEN** that notice is not shown, and a second active notice still is
- e2e: `tests/e2e/operate-maintenance-notice.spec.ts`

### Requirement: Page editors manage notices (REQ-OMN-003)

Members of the page editor groups and administrators SHALL be able to create,
change and delete notices on a "Notices" admin page. A notice SHALL require
an end time, and the form SHALL refuse an end before the start with "The end
must be after the start."

#### Scenario: An editor schedules a notice
- **GIVEN** a page editor who is not an administrator
- **WHEN** they open "Notices", write a message, set the window and publish
- **THEN** the notice appears on the site when its window starts
- e2e: `tests/e2e/operate-maintenance-notice.spec.ts`

#### Scenario: A user outside the editor groups cannot write one
- **GIVEN** a signed-in user outside the editor groups
- **WHEN** they try to create a `portalNotice` through OpenRegister's API
- **THEN** the write is refused
- @e2e exclude Schema authorization; pinned by PageEditorServiceTest and a Newman call

### Requirement: A portal's notices tab lists its notices as a list page does (REQ-PND-001)

The Mededelingen tab of a portal's page SHALL list that portal's notices with quick filters and their counts (all, visible now, upcoming, draft), a Filter menu, the view switch, search, selection, six rows a page and a note under the list. Each row SHALL lead with a tile for its level (a warning on the warning tone) and name under the text where the notice shows, in words. A published notice whose window runs SHALL read "Visible now", one whose window has passed "Expired". A row SHALL open the notice's page. The list's Actions menu SHALL stand beside the tabs.

#### Scenario: the notices tab of Gemeente Zuiddrecht
- **GIVEN** the portal Gemeente Zuiddrecht with fourteen notices
- **WHEN** Pieter opens its Mededelingen tab
- **THEN** he reads Alles 14 and the other filters with their counts, and six notices on the first page
- **AND** the DigiD notice leads with a warning tile and reads "Waarschuwing · toont op Mijn omgeving"
- @e2e exclude the list is manifest configuration over nc-vue's index-list; covered by tests/portal-notices-tab-as-a-list.spec.mjs and nc-vue's notice-list-in-a-tab tests

#### Scenario: the header on the notices tab
- **GIVEN** Pieter on the Mededelingen tab
- **THEN** the header offers Website bekijken and the primary Nieuwe mededeling, and names the portal's pages
- @e2e exclude covered by tests/portal-notices-tab-as-a-list.spec.mjs
