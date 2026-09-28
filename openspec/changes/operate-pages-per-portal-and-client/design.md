# Design: operate-pages-per-portal-and-client

Read at portaliq `development` `4f460b3`.

## Where the navigation comes from today

- `lib/Contribution/PortalContributionRegistry.php:102` `aggregateFor()`
  walks the installed apps, keeps the providers that serve the subject's
  audience (`servesAudience()`, :354), filters each contribution by trust
  (`filterByTrust()`, :387) and normalises it. Nothing else decides what a
  subject gets.
- `lib/Controller/ContributionController.php:293` `index()` answers that
  aggregate to the SPA. The collection, object, action and inbox routes call
  `aggregateFor()` again and authorise against it: `authorisedCollection()`
  (:528) returns null, and the route refuses, for a collection that is not in
  the subject's aggregate.
- `src/portal/App.jsx:47` `buildNav()` flattens every contribution's `pages`
  into the navigation, in aggregate order, then appends "My tasks" and
  "Inbox".
- Schema `portal` (`lib/Settings/portaliq_register.json`): `title`, `slug`,
  `domains`, `theme`, `authentication`, `organisation` and more; no page
  choice. Schema `portalAccount`: `audience`, `subjectRef`, `organisation`,
  `status` and contact fields; no page choice.
- `lib/Service/PortalResolver.php:118` `resolve()` finds the serving portal
  from the host or a named slug.

## D1. The portal choice is presentation, the client choice is access

The two choices do different jobs, so they sit at different seams.

- A portal's choice decides what the navigation of that portal shows. It is
  applied in `ContributionController::index()`, where the request, and so
  the serving portal, is known. A page hidden on one portal stays reachable
  through the collection routes, because the same subject may use another
  portal of the organisation where it is shown.
- A client's choice decides what that account may see. It is applied in
  `aggregateFor()`, which every authorising route calls, so a hidden page's
  collections are refused everywhere for that account.

Alternative considered: one choice in `aggregateFor()` for both. Rejected:
`aggregateFor()` has no request, and a portal is resolved from the request,
so the portal choice would have to ride in the session. Nothing in the
session names a portal today.

## D2. The portal choice

`portal.navigation`: an object keyed by audience, each value a list of
`{ page: "<app>:<pageId>", hidden: bool }` in the order they are shown.
`index()` resolves the serving portal with `PortalResolver::resolve()`, and
when the portal has a list for the subject's audience it drops the hidden
pages from the answer and orders the rest by the list. Pages not in the list
(an app installed later) keep their place after the listed ones, so a new
app is visible until someone decides otherwise.

The admin screen is a "Navigation" section on the portal detail page: for
each audience, the pages the installed apps contribute for it, read from
`aggregateFor()` with a synthetic subject of that audience at the highest
trust, each with a show toggle and up and down buttons.

## D3. The client choice

`portalAccount.hiddenPages`: a list of `<app>:<pageId>`. It is staff-only:
the account's own self-service routes never write it (the existing
self-service PATCH whitelist does not include it), and it is edited on the
account detail page of `identity-staff-account-screens`.

`aggregateFor()` reads the account by `subjectRef` once per request (a
per-request memo, since one request can call it several times) and, when the
list is not empty, removes those pages from the contribution, then removes
every collection that no remaining page references. `authorisedCollection()`
then refuses those collections with the answer it gives for any undeclared
one.

## D4. What the SPA shows

`buildNav()` keeps its shape; it receives fewer pages in a set order. A
hidden page that is the target of a deep link (a task or inbox row pointing
into it) is not in the navigation; the deep link lands on the first page, as
it does for any page the subject cannot see today.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| The choices | Declarative, two properties on existing schemas | Data. |
| Applying the portal choice | Imperative, `ContributionController::index()` | Needs the serving portal from the request. |
| Applying the client choice | Imperative, `PortalContributionRegistry::aggregateFor()` | Must hold for every authorising route. |

## Seed data

Portal `open-tilburg` hides pipelinq's quotes page for the `client`
audience. Account "Bakkerij De Kroon B.V." has pipelinq's invoices page
hidden.

## Risks

- **A hidden page thought to be a closed door.** The portal choice is not
  access control; the admin screen says so under the section title ("Hidden
  pages are left out of this portal's menu. To stop one client reading them,
  hide them on the client's account.").
- **One more read per request.** The account read is memoised per request and
  is skipped for a subject without a `subjectRef`.

## What it deliberately does not do

- It does not filter records inside a collection.
- It does not rename or re-icon a contributed page.
