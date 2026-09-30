# Design: woo-journey-entry-points

Read at portaliq `development` `0e0cfc8d`.

## What exists

- `src/site/App.vue` holds `session` from `fetchSession()` (`/portal/api/session`
  with the bearer from `sessionStorage`). `WidgetGrid.vue` `propsFor()` gives
  host-supplied props to blocks after the authored ones.
- `ContributionController::action()` (`POST /portal/api/actions/{appId}/{actionId}`)
  forwards an endpoint action found in the subject's own aggregated manifest,
  with a signed `X-Portal-Subject`, after `whitelist()` keeps only its `fields`.
- `GET /portal/api/collections/{register}/{schema}` lists the subject's own rows
  of a declared collection; `GET /portal/api/contributions` answers the manifest.
- `PortalRowActionController::forward()` proves the row through the collection's
  scope (`ownedRow()`), then forwards the collection's own row action with
  `rowField` set to the row id. It only looks in the collection's own contribution.
- `PortalSelfServiceService::removeAccount()` empties the account and writes
  `status: removed` through OpenRegister, which raises `ObjectUpdatedEvent`.
  opencatalogi listens for that (hydra C7); portaliq adds nothing.
- `PortalRecordChangeListener::onCreated()` returns early for every
  `portaliq/portalMessage` ("dispatched by whoever wrote them"). Another app that
  writes one gets the inbox entry and nothing else.
- `NotificationDispatchService::dispatch(ruleKey, appId, subject, record)` queues
  the email only when the app's contribution declares the rule key.

## D1. Signed-in state on the site

`WidgetGrid` gains a `signedIn` prop, and `App.vue` passes `session !== null`.
`propsFor()` hands `signedIn` to `federatedSearch` and `publicationDetail`
after the authored props, so a page cannot switch it on. The blocks read the
bearer with `adoptSessionToken()` only when they call an action.

## D2. The resident's actions from the site

`src/site/lib/residentActions.js`, pure where it can be:

- `offeredActions(manifest, appId)` returns the action ids of `appId` in the
  subject's manifest. The blocks show a button only when its action id is
  offered. So a portal without opencatalogi, or an audience it does not serve,
  shows nothing.
- `addToCollectionBody({ collection, title, publication, attachment })` and
  `saveSearchBody({ title, frequency, query })`.
- `postAction(authBase, appId, actionId, body)` returns `{ ok, status, body }`.

Defaults, each a block prop: app `opencatalogi`, actions `addToDossier` and
`saveSearch`, dossier collection `opencatalogi/collection`. The opencatalogi
lane names the real ids; the coordinator aligns the defaults before merge.

The save panels load on demand inside the already-async blocks: a small
`SaveToDossier.vue` and `SaveSearch.vue`. The dossier picker lists the
resident's dossiers and offers "Nieuw dossier" with a title field. Frequency
offers "Direct", "Dagelijks" (default) and "Wekelijks".

## D3. Attached actions

`AttachedActionResolver` runs after aggregation in
`PortalContributionRegistry`. For every endpoint action with
`attachTo: { app, schema }` and a valid `rowField`, it adds
`{ app, id, label, fields, fieldConfigs, submitLabel, successMessage }` to
`attachedActions` on each collection of `app` whose `schema` equals
`attachTo.schema`. It never changes the action itself. An `attachTo` that is
not two plain strings is dropped with the action's attachment only; the action
stays an ordinary action of its own app.

`PortalRowActionController::forward()` accepts `actionApp`. When it names
another app, the action must be listed in the collection's `attachedActions`
for that app and id, and must still be in that app's own contribution. The row
is proven through the TARGET collection's scope, exactly as for its own row
actions, then the action is forwarded to its own app with `rowField` set to the
row id. The receiving app still checks ownership itself (contract C4, C5).

In the React portal, `DetailCard` renders each attached action as a button.
`AttachedActionDialog` shows the action's fields, then calls
`api.forwardRowAction(collection, rowId, id, body, app)`.

## D4. Delivering another app's notice

`portalMessage` gains an optional `ruleKey` (schema `0.5.0` to `0.6.0`, register
`0.51.0` to `0.52.0`). `PortalRecordChangeListener::onCreated()` keeps skipping
portaliq's own writes (the write context guard), and for a `portalMessage`
written by another app with a `ruleKey` it resolves the account and calls
`dispatch(ruleKey, app, subject, recordLink)`. The app is the part of the rule
key before the first dot. `dispatch()` already refuses a key the app does not
declare, so a message cannot borrow another app's key. Berichtenbox is not
queued: not in this journey (hydra #730).
