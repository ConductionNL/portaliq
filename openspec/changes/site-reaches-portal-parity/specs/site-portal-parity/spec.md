---
status: proposed
---

# Spec: site-portal-parity

## Purpose

A resident does on `/apps/portaliq/site` everything they can do on
`/apps/portaliq/portal` today, and old `/portal` links keep working. Each
requirement below is one capability of `src/portal/`; `design.md` maps it to
its React source, the Vue target and the node test that covers it. The "test:"
line names the node spec that must pass against the Vue port or the moved
library.

## ADDED Requirements

### Requirement: A framework-free library MUST move unchanged and keep its test (REQ-SRP-052)

Every library under `src/portal/` that imports neither React nor JSX SHALL move
to `src/shared/` with its behaviour unchanged. Both bundles SHALL import it from
there until the React portal is deleted, and its node test SHALL read it from
the new path.

#### Scenario: A moved library keeps its test green
- **GIVEN** `src/portal/lib/rowAction.js` moved to `src/shared/rowAction.js`
- **WHEN** `npm run check:specs` runs
- **THEN** `tests/row-action.spec.mjs` reads the new path and passes
- test: `tests/row-action.spec.mjs`

### Requirement: The site MUST boot from its own runtime config (REQ-SRP-001)

The site SHALL read its configuration from the `#portaliq-site-config` block
`templates/site.php` writes, and SHALL NOT read any Nextcloud global. Anything
the React portal read from `runtimeConfig` that the site needs SHALL reach it
through that block or the public content API.

#### Scenario: The site boots with no Nextcloud global
- **GIVEN** a page served by `PortalPageController::site()`
- **WHEN** the bundle mounts
- **THEN** it renders the portal without reading `OC`, `OCA` or a `requesttoken`
- test: `tests/site-head.spec.mjs`

### Requirement: The site MUST adopt and keep one bearer for the resident (REQ-SRP-002)

The site SHALL adopt a `#token=` fragment, strip it from the address, keep the
bearer in one store, and read the session from `/portal/api/session`. The
shared portal API adapter SHALL read that same store. A bearer kept by the React
portal under localStorage `portaliq_token` SHALL be adopted once or the resident
SHALL be asked to sign in again; it SHALL NOT be ignored silently.

#### Scenario: A callback fragment signs the resident in
- **GIVEN** a browser landing on `/site?portal=wilgenboom#token=abc`
- **WHEN** the site boots
- **THEN** the address no longer carries the token and the session read sends it
- test: `tests/site-auth.spec.mjs`

### Requirement: A signed-out resident MUST see a sign-in screen (REQ-SRP-003)

The site SHALL show a sign-in screen with one button per provider the portal
declares, the failed sign-in message after `#signin=failed`, a hint when no
provider is configured, and the dev-login only when the server allows it. A
portal whose content API answers 401 `authentication_required` SHALL show that
sign-in screen, not an error.

#### Scenario: An auth-gated portal, signed out
- **GIVEN** the portal `wilgenboom`, whose content API answers 401 `authentication_required` with modes `["digid"]`
- **WHEN** a signed-out visitor opens `/site?portal=wilgenboom`
- **THEN** they see a sign-in screen with a DigiD button, not "Er ging iets mis"
- test: `tests/broker-login.spec.mjs`, `tests/site-auth.spec.mjs`

### Requirement: Silent sign-in MUST be tried once per browser session (REQ-SRP-004)

When the organisation turned silent sign-in on, the site SHALL try it on the
first signed-out load of a browser session, and never after a sign-out, an
inactivity sign-out or a failed sign-in.

#### Scenario: No second attempt after signing out
- **GIVEN** silent sign-in is on and the resident signed out
- **WHEN** they load the site again in the same tab
- **THEN** the site does not navigate to the silent sign-in address
- test: `tests/idle-session.spec.mjs`

### Requirement: The idle window MUST warn, refresh and sign out (REQ-SRP-005)

Activity in the second half of the idle window SHALL refresh the bearer. Without
activity a warning SHALL open before expiry, with a countdown in a polite live
region. At expiry the site SHALL sign the resident out and say why.

#### Scenario: Signed out for inactivity
- **GIVEN** a signed-in resident who does nothing past the idle window
- **WHEN** the window ends
- **THEN** the sign-in screen says "You were signed out because you were inactive." in the site's language
- test: `tests/idle-session.spec.mjs`

### Requirement: Sign-out MUST end the session at the edge and at the broker (REQ-SRP-006)

Signing out SHALL send the bearer with `DELETE /portal/api/session`, forget it
locally even when the edge refuses, and follow the broker's own sign-out address
when the answer carries one.

#### Scenario: A broker sign-out address is followed
- **GIVEN** the edge answers the sign-out with a broker logout address
- **WHEN** the resident signs out
- **THEN** the browser goes to that address
- test: `tests/idle-session.spec.mjs`

### Requirement: Navigation MUST be built from the pages contributions declare (REQ-SRP-007)

For a signed-in resident the site SHALL list every page each contribution
declares, plus "My cases" first when a contribution declares cases, and "My
tasks", "Messages", "News", "Inbox", "Access to cases", "My details" and "My
account" under the same conditions the React portal uses. The inbox entry SHALL
show the unread count. The default screen SHALL be the first content page,
never the inbox. Each entry SHALL have its own address, so a reload or a shared
link opens the same screen.

#### Scenario: A guardian's menu
- **GIVEN** Fatima, signed in on `wilgenboom`, with learniq contributing pages and one unread message
- **WHEN** the site loads
- **THEN** the menu lists learniq's pages, Inbox with "1", and My account, and the first learniq page is open
- test: new `tests/resident-nav.spec.mjs` on `src/site/lib/residentNav.js`

### Requirement: The tab and header MUST carry the portal's own name (REQ-SRP-008)

The document title and the header SHALL name the serving portal, never
"Nextcloud", and the root element SHALL carry the portal's theme class.

#### Scenario: The tab title
- **GIVEN** the portal "Ouderportaal De Wilgenboom"
- **WHEN** any site page loads
- **THEN** the tab title contains "Ouderportaal De Wilgenboom"
- test: `tests/site-head.spec.mjs`, `tests/site-shell-blocks.spec.mjs`

### Requirement: Every visible string MUST be translated (REQ-SRP-009)

The site SHALL render every string through one translator for the site's locale
(nl or en), from catalogues in `src/shared/i18n/`. A missing key SHALL fall back
to the English source, never to an empty string.

#### Scenario: English portal
- **GIVEN** a portal whose locale is `en`
- **WHEN** a signed-in screen renders
- **THEN** no Dutch literal from the shell appears on it
- test: `tests/message-box-channel.spec.mjs`, `tests/open-record.spec.mjs` (key presence)

### Requirement: Notices MUST show above every page (REQ-SRP-010)

Active maintenance and warning notices SHALL show above every page, signed in
or not, decided by the shared `notices.js`, without an alert role.

#### Scenario: A running maintenance notice
- **GIVEN** a notice active now for this portal
- **WHEN** a resident opens any page
- **THEN** the notice shows above the content
- test: `tests/notices.spec.mjs`

### Requirement: A business user MUST be able to choose a branch (REQ-SRP-011)

A whole-company eHerkenning session with more than one branch SHALL show a
branch switcher in the header. A restricted session SHALL show its branch as
text. A refused choice SHALL say "That branch could not be chosen."

#### Scenario: Narrowing to a branch
- **GIVEN** a company session with two branches
- **WHEN** the user picks one
- **THEN** the session bearer names that branch and the lists read again
- test: `tests/branch-choice.spec.mjs`, `tests/branch-in-effect.spec.mjs`

### Requirement: Loading MUST be announced politely (REQ-SRP-012)

Every loading state SHALL be a polite status region that a screen reader
announces as "Loading…" in the site's language.

#### Scenario: Loading a page
- **GIVEN** a slow content answer
- **WHEN** the site waits for it
- **THEN** a `role="status"` region reads the translated "Loading…"
- test: `tests/portal-live-regions.spec.mjs`

### Requirement: Ported screens MUST be styled with tokens only (REQ-SRP-013)

A ported screen SHALL use NL Design System classes and CSS custom properties,
with no colour literal, and SHALL meet WCAG 2.1 AA contrast and keyboard reach.
`src/portal/theme.css` SHALL NOT be loaded by the site.

#### Scenario: No colour literal
- **GIVEN** the ported components under `src/site/`
- **WHEN** `npm run stylelint` runs
- **THEN** it reports no hard-coded colour
- test: `npm run stylelint`

### Requirement: A contribution page MUST render its blocks (REQ-SRP-014)

The site SHALL render one contribution page's ordered blocks: `richText`,
`collection`, `detail`, `citizenCase`, `action`, `cta`, and a timed task
collection. An unknown block type SHALL render nothing.

#### Scenario: A page with a table and a form
- **GIVEN** a contribution page with a `collection` block and an `action` block for a create action
- **WHEN** the resident opens it
- **THEN** the table and the form both show
- test: `tests/attached-actions.spec.mjs`, `tests/my-dossiers.spec.mjs` (rewritten against the Vue page)

### Requirement: A collection table MUST follow the declared columns (REQ-SRP-015)

The table SHALL show the collection's declared `columns` with their `render`
formatter, else the row keys. Rows SHALL be selectable with the keyboard. Only
`type: update` and endpoint row actions SHALL appear as row buttons.

#### Scenario: Keyboard selection
- **GIVEN** a table with three rows
- **WHEN** the resident moves to the second row and presses Enter
- **THEN** that row opens in the detail block
- test: `tests/collection-table-keyboard.spec.mjs`

### Requirement: Collections MUST reload after a write (REQ-SRP-016)

A page SHALL load every collection its blocks reference. After a create or
update, every collection reading that register and schema SHALL load again, and
the inbox unread count SHALL refresh.

#### Scenario: A new row shows
- **GIVEN** a page with a table and a create form on the same schema
- **WHEN** the resident submits the form
- **THEN** the new row is in the table without a reload
- test: new `tests/collection-loader.spec.mjs`

### Requirement: A detail card MUST show one record (REQ-SRP-017)

The detail block SHALL show the selected row's fields, its files with upload and
download, its proposals with a propose-change action, its item list, its
attached actions and its timeline.

#### Scenario: Downloading a file
- **GIVEN** a record with one file
- **WHEN** the resident downloads it
- **THEN** the file arrives through `/portal/api` with their bearer
- test: `tests/my-dossiers.spec.mjs`

### Requirement: Rich text MUST stay text only (REQ-SRP-018)

A `richText` block SHALL render headings and paragraphs and SHALL NOT render raw
HTML from the manifest.

#### Scenario: A script tag in markdown
- **GIVEN** a `richText` block containing `<script>`
- **WHEN** it renders
- **THEN** no script element is created
- test: new `tests/rich-text.spec.mjs`

### Requirement: A record's timeline MUST show as its app returned it (REQ-SRP-019)

The timeline SHALL list the entries the contributing app returned, in its order,
adding and filtering nothing.

#### Scenario: A case history
- **GIVEN** a case whose collection declares a timeline with two entries
- **WHEN** the resident opens the case
- **THEN** both entries show under the timeline label
- test: `tests/case-timeline.spec.mjs`

### Requirement: An item list MUST show and remove items (REQ-SRP-020)

A collection with `itemList` SHALL show the items its provider returns, mark an
item that is no longer public, and offer remove per item when a remove action is
declared.

#### Scenario: Removing one item
- **GIVEN** a dossier with two items and a remove action
- **WHEN** the resident removes one
- **THEN** one item remains
- test: `tests/my-dossiers.spec.mjs`

### Requirement: A record link MUST open its record after sign-in (REQ-SRP-021)

An `#open=<app>/<collection>/<id>` fragment SHALL be read and stripped on load,
kept in sessionStorage across a sign-in, and open the page that shows that
collection with the record selected. A record not in the resident's list SHALL
say so and show nothing of it.

#### Scenario: A mail link while signed out
- **GIVEN** a signed-out resident following a notification link with `#open=`
- **WHEN** they sign in
- **THEN** the record's page opens with the record selected
- test: `tests/open-record.spec.mjs`

### Requirement: A schema form MUST render only whitelisted fields (REQ-SRP-022)

The form SHALL render exactly the action's `fields`, shaped by `fieldConfigs`,
with options from `optionsProviders` (static, or a subject-scoped collection).
It SHALL replace `ActionFieldsForm.jsx` as well.

#### Scenario: A dropdown from a collection
- **GIVEN** a field whose options provider is one of the resident's collections
- **WHEN** the form opens
- **THEN** the dropdown lists only that resident's rows
- test: new `tests/schema-form.spec.mjs`

### Requirement: A file field MUST upload after the record exists (REQ-SRP-023)

On submit the form SHALL create the record without its file fields, then upload
each picked file to it, and report a partial failure in words.

#### Scenario: One upload fails
- **GIVEN** a form with two files where the second upload fails
- **WHEN** the resident submits
- **THEN** the record exists and the message names the failed file
- test: `tests/schema-form-file-field.spec.mjs`

### Requirement: A resident MUST be able to propose a change (REQ-SRP-024)

A `propose-change` action SHALL render its `proposable` fields pre-filled from
the row, plus a note, and send only the changed fields.

#### Scenario: One changed field
- **GIVEN** a profile with a phone number and an address
- **WHEN** the resident changes only the phone number and submits
- **THEN** the proposal carries only the phone number and the note
- test: new `tests/propose-change.spec.mjs`

### Requirement: A status transition MUST send no field data (REQ-SRP-025)

A `type: update` row action SHALL be sent with no field data, so the server's
`set` decides the transition, and the collection SHALL load again.

#### Scenario: Approving a row
- **GIVEN** a row with an "Approve" update action
- **WHEN** the resident presses it
- **THEN** the request body is empty and the table reloads
- test: new `tests/collection-loader.spec.mjs`

### Requirement: An endpoint row action MUST confirm before it runs (REQ-SRP-026)

An endpoint row action SHALL open a confirm step with the row's notice, run only
on confirm, show the outcome, and show the answer's link when it has one. It
SHALL show only on rows its `rowWhen` names.

#### Scenario: Pay a contribution
- **GIVEN** a voluntary contribution row with "Pay now"
- **WHEN** the guardian presses it
- **THEN** a confirm step says the contribution is voluntary and nothing is sent yet
- test: `tests/row-action.spec.mjs`

### Requirement: An endpoint action MUST show its answer or follow its redirect (REQ-SRP-027)

An `action` or `cta` block for an endpoint action SHALL forward it through
portaliq, follow a checked redirect, and otherwise show the translated answer.
The answer SHALL clear when the resident moves to another screen.

#### Scenario: An answer with no redirect
- **GIVEN** an endpoint action whose leaf app answers with a message
- **WHEN** the resident runs it
- **THEN** the message shows in a status region on that page
- test: `tests/row-action.spec.mjs`

### Requirement: Another app's actions MUST show on a record (REQ-SRP-028)

The detail block SHALL show one button per attached action, open its fields, and
forward them through the row-action route with `actionApp`.

#### Scenario: Ask a question about a dossier
- **GIVEN** a dossier with pipelinq's attached question action
- **WHEN** the resident fills it in and sends it
- **THEN** the forward names pipelinq as `actionApp`
- test: `tests/attached-actions.spec.mjs`

### Requirement: A document MUST be signable and declinable from its row (REQ-SRP-029)

The sign dialog SHALL show the document with a download link and enable signing
only after it is shown. The decline dialog SHALL ask why and keep the dialog
open with the answer when refused.

#### Scenario: Declining with a reason
- **GIVEN** a document awaiting signature
- **WHEN** the resident declines with a reason
- **THEN** the reason is forwarded and the outcome shows
- test: `tests/signing-dialog.spec.mjs`

### Requirement: The inbox MUST merge every app's messages (REQ-SRP-030)

The inbox SHALL list the aggregated messages newest first, with unread markers,
mark read, the message-box delivery state, "Open" for a record link and "View
task" for a task link.

#### Scenario: Marking a message read
- **GIVEN** one unread message
- **WHEN** the resident marks it read
- **THEN** the unread count in the menu drops by one
- test: `tests/message-box-channel.spec.mjs`, `tests/translated-message-notice.spec.mjs`

### Requirement: Notification choices MUST be settable per kind (REQ-SRP-031)

The inbox SHALL offer collapsed notification settings: per kind an e-mail and a
push checkbox, the push column only when a device is registered and a push
transport that really delivers is bound, and the message
box channel when the organisation offers it.

#### Scenario: Turning off e-mail for one kind
- **GIVEN** the settings open
- **WHEN** the resident unticks e-mail for one kind and saves
- **THEN** the saved preferences carry that change only
- test: `tests/message-box-channel.spec.mjs`

### Requirement: A guardian MUST read messages in their chosen language (REQ-SRP-032)

The messages screen SHALL list the guardian's threads, offer a language picker
that writes `messageLanguage`, and show a translated message with its notice.
It SHALL show only when the guardian takes part in a thread.

#### Scenario: Switching to English
- **GIVEN** a thread in Dutch
- **WHEN** the guardian picks English
- **THEN** messages show their English translation with the AI notice
- test: `tests/news-item-translation.spec.mjs`

### Requirement: A guardian MUST read school news (REQ-SRP-033)

The news screen SHALL list news items and the newsletter archive in the chosen
language, and SHALL show only when the feed holds an item.

#### Scenario: No news
- **GIVEN** an empty news feed
- **WHEN** the guardian signs in
- **THEN** there is no News entry in the menu
- test: `tests/news-title-and-newsletter-translation.spec.mjs`, `tests/newsletter-title-translation.spec.mjs`

### Requirement: A machine translation MUST say so (REQ-SRP-034)

A translated text SHALL show the translation, then a notice "Translated by AI
from Dutch" in an `aside` landmark, with a way to read the original.

#### Scenario: Reading the original
- **GIVEN** a translated message
- **WHEN** the reader asks for the original
- **THEN** the Dutch text shows
- test: `tests/translated-message-notice.spec.mjs`

### Requirement: A resident MUST be able to complete their tasks (REQ-SRP-035)

"My tasks" SHALL list open portal tasks, open one from an inbox deep link, and
complete it with a comment and files within the task's upload limits.

#### Scenario: From the inbox to the task
- **GIVEN** an inbox message carrying a task link
- **WHEN** the resident presses "View task"
- **THEN** "My tasks" opens with that task open
- test: new `tests/tasks-page.spec.mjs` (none exists today)

### Requirement: A pupil MUST be able to take a timed task (REQ-SRP-036)

A timed task collection SHALL show the tests a pupil can start, their attempts,
the question screen with a countdown, every question type, and the released
result, through the collection's five endpoint actions.

#### Scenario: Time runs out
- **GIVEN** an attempt with ten seconds left
- **WHEN** the clock reaches zero
- **THEN** the saved answers are submitted and the screen says so
- test: `tests/timed-task.spec.mjs`

### Requirement: A resident MUST manage their own account (REQ-SRP-037)

"My account" SHALL show and change the display name, the e-mail addresses and
phone numbers with the preferred one marked, the contact channel, and offer
removal. A `#confirm-email=` fragment SHALL be read once and confirmed. A
session without an e-mail address SHALL see a dismissible prompt.

#### Scenario: Confirming an e-mail address
- **GIVEN** a confirmation link
- **WHEN** the resident opens it
- **THEN** the site says "Your e-mail address is confirmed."
- test: `tests/account-page.spec.mjs`

### Requirement: A resident MUST see their registered details (REQ-SRP-038)

"My details" SHALL show the BRP record for a resident and the KvK record for a
business user, read when the screen opens, with an empty and an unavailable
state.

#### Scenario: No source configured
- **GIVEN** an instance with no BRP source
- **WHEN** the resident opens "My details"
- **THEN** the screen says the details are unavailable
- test: `tests/registered-details.spec.mjs`

### Requirement: A resident MUST be able to ask for access to cases (REQ-SRP-039)

"Access to cases" SHALL let a signed-in user ask for access to a party's cases
with a reason, and list every request they made with its state.

#### Scenario: A new request
- **GIVEN** a signed-in user
- **WHEN** they ask for access for a company with a reason
- **THEN** the request is listed as pending
- test: `tests/access-request-asker.spec.mjs`

### Requirement: A resident MUST see every case in one list (REQ-SRP-040)

"My cases" SHALL list every case from every `kind: cases` collection newest
first, mark closed cases, and open a case on its app's page with the case
selected, also when read under a mandate.

#### Scenario: Opening a case
- **GIVEN** a case from dossiq in "My cases"
- **WHEN** the resident opens it
- **THEN** dossiq's case page opens with that case selected
- test: `tests/my-cases-page.spec.mjs`

### Requirement: A resident MUST be able to act for someone else (REQ-SRP-041)

The header SHALL offer "acting for" with the resident and each mandate held,
kept for the session, applied to "My cases" and every case screen. A refusal
SHALL NOT forget the mandates.

#### Scenario: Switching to a mandate
- **GIVEN** a resident holding one mandate
- **WHEN** they choose to act for it
- **THEN** "My cases" lists the cases read under that mandate
- test: `tests/my-cases-acting-for.spec.mjs`

### Requirement: A citizen MUST work on their own case (REQ-SRP-042)

The citizen case block SHALL render the server's writable set: amend the fields
it allows, add a document, show documents grouped decision first, and download
them.

#### Scenario: Adding a document
- **GIVEN** a case that allows adding documents
- **WHEN** the citizen adds one
- **THEN** it shows under "what you sent"
- test: `tests/case-documents-screen.spec.mjs`, `tests/case-type-portal-header.spec.mjs`

### Requirement: A citizen MUST be able to withdraw a request (REQ-SRP-043)

When the server allows withdrawal, the case SHALL offer it behind a confirm step
that explains what withdrawing means, asks an optional reason, and sends nothing
until confirmed.

#### Scenario: Cancelling the withdrawal
- **GIVEN** the confirm step open
- **WHEN** the citizen cancels
- **THEN** nothing is sent and focus returns to the withdraw button
- test: `tests/case-withdraw-screen.spec.mjs`

### Requirement: The site MUST be installable (REQ-SRP-044)

`templates/site.php` SHALL link the web app manifest for the serving portal, and
the manifest's `start_url` SHALL point at `/site` with the same portal.

#### Scenario: The manifest names the site
- **GIVEN** `/site?portal=wilgenboom`
- **WHEN** the browser reads the linked manifest
- **THEN** `start_url` is `/apps/portaliq/site?portal=wilgenboom`
- test: PHPUnit `PortalManifestControllerTest`

### Requirement: The service worker MUST cache the site shell (REQ-SRP-045)

The service worker SHALL be served from a file outside `src/portal/`, cache the
site's shell and never its API, and be registered by the site without blocking
boot when registration fails.

#### Scenario: Registration fails
- **GIVEN** a browser that refuses service workers
- **WHEN** the site boots
- **THEN** the site renders normally
- test: new `tests/service-worker.spec.mjs`

#### Scenario: The allowed scope covers the registered scope
- **GIVEN** portaliq installed in `apps/` or in `custom_apps/`, reached with or without `index.php`, under any web root
- **WHEN** the site registers the worker with scope `<route root>/`
- **THEN** the worker's `Service-Worker-Allowed` header SHALL name that same route root, read off the address the worker was requested on, never the app's file path (`/custom_apps/portaliq/`)
- test: `tests/Unit/Controller/PortalManifestControllerTest.php::testTheAllowedScopeIsTheScopeTheSiteRegisters`, `tests/service-worker.spec.mjs` "the scope the site registers is the scope the server allows"

### Requirement: The install offer MUST be dismissible (REQ-SRP-046)

When the browser offers installation, the site SHALL show its own control to
install or dismiss it, and show nothing on a browser that makes no offer.

#### Scenario: Not now
- **GIVEN** the browser fired `beforeinstallprompt`
- **WHEN** the resident presses "Not now"
- **THEN** the control disappears for this page view
- test: new `tests/install-banner.spec.mjs`

### Requirement: The embed frame MUST render its form from a small entry (REQ-SRP-047)

`templates/embed.php` SHALL load an embed entry that does not include the site
or the React portal, render the form or its refusal in words, and report its
height to the host page.

#### Scenario: A refused embed still has a height
- **GIVEN** an embed request from an origin the portal does not allow
- **WHEN** the frame renders
- **THEN** it shows the refusal and reports a height above zero
- test: `tests/embed-copy.spec.mjs`, `tests/embed-height.spec.mjs` (both wired into `check:specs`)

### Requirement: Old portal links MUST land on the site (REQ-SRP-048)

`GET /apps/portaliq/portal` SHALL answer 302 to `/apps/portaliq/site` with the
same query string. A tenant named by `?org=` SHALL resolve to the same portal on
`/site`. The `/portal/api/*` routes SHALL stay as they are.

#### Scenario: A bookmarked portal address
- **GIVEN** a signed-out browser
- **WHEN** it requests `/apps/portaliq/portal?portal=wilgenboom`
- **THEN** the answer is 302 with `Location` ending `/apps/portaliq/site?portal=wilgenboom`
- test: PHPUnit `PortalPageControllerTest`

### Requirement: The server MUST link to the site directly (REQ-SRP-049)

The OIDC callback, the broker callback, the failed sign-in redirect, notification
and task mail links, and the manifest `start_url` SHALL point at the site route,
not at `/portal`.

#### Scenario: A notification mail
- **GIVEN** a task delivered to a resident
- **WHEN** the mail is built
- **THEN** its link starts with the absolute `/apps/portaliq/site` address
- test: PHPUnit `PortalDeepLinkBuilderTest`, `SessionControllerTest`

### Requirement: The React portal MUST be deleted (REQ-SRP-050)

After parity, `src/portal/`, `webpack.portal.js`, `templates/portal.php`, the
`build:portal` and `watch:portal` scripts, `react`, `react-dom` and
`@utrecht/component-library-react` SHALL be removed, and no test SHALL compile
JSX.

#### Scenario: No React left
- **GIVEN** `development` after retirement
- **WHEN** `npm ls react` runs
- **THEN** it lists nothing
- test: `npm run build` (no `portaliq-portal.js` emitted)

### Requirement: Parity MUST be measured before the React portal goes (REQ-SRP-051)

The React portal SHALL be deleted only when every line of this checklist holds
on `development`:

1. Every requirement above has a ticked task in this change.
2. `git grep -n "portal/lib\|portal/i18n" src/site src/shared` finds nothing.
3. `npm run check:specs` runs every node test named above, `embed-copy` and `embed-height` included, and passes.
4. As the guardian Fatima on `/site?portal=wilgenboom`: the sign-in screen, My cases, the inbox with its count, messages, news, tasks, My account, My details, Access to cases and each learniq page render, each with a saved screenshot.
5. `/portal?portal=wilgenboom` answers 302 to `/site?portal=wilgenboom`.
6. A mail link, an OIDC callback and a broker callback land on `/site`, signed in, with the named record open.
7. The site entry is under its `webpack.site.js` budget.
8. No Playwright spec in portaliq or learniq opens `/apps/portaliq/portal` except the redirect test.

`docs/portal-parity.md` SHALL record the result with its date.

#### Scenario: A missing screen blocks deletion
- **GIVEN** every line holds except that "My tasks" has no Vue screen
- **WHEN** the retirement task is reviewed
- **THEN** it is not ticked and `src/portal/` stays
- test: review of `docs/portal-parity.md` against this list
