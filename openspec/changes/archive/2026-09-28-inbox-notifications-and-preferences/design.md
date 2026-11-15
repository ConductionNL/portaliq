# Design: inbox-notifications-and-preferences

Read at portaliq development `eeda3fa`.

## What is there today

- `lib/Service/NotificationDispatchService.php` holds two rule keys, `RULE_MESSAGE_CREATED = 'message.created'` and `RULE_STATUS_CHANGED = 'status.changed'`. `dispatch()` aggregates the subject's contributions, and `declaresRuleKey()` (line 174) enqueues `NotificationDispatchJob` only when the app's `notifications` list holds that exact string.
- Its two call sites: `lib/Service/SubmissionReceiptService.php:279` after a receipt message, and `lib/Controller/ContributionController.php:1262` after the resident's own update action.
- `lib/BackgroundJob/NotificationDispatchJob.php:178` `doRun()` resolves the account, skips it when `notificationChannels.email` is `false` (`optedOutOfEmail()`, line 374), sends one bilingual e-mail (`sendEmail()`, line 262) whose text is always "You have a new message in the portal of %1$s" (lines 111 and 113), logs a `portalNotification` row with `channel` `email` (line 91), and flags `needsAlternativeContact` after repeated failures.
- `lib/Service/PortalDeepLinkBuilder.php` has one method, `forOrganisation()`, the portal root with `?org=`.
- `lib/Service/Notifications/PushDeliveryService.php:71` `deliver(subjectRef, title, body, emergency)` sends a web push to a subject's registered devices and respects `QuietHoursPolicy`.
- `lib/AppInfo/Application.php:143` already registers a listener on OpenRegister's `ObjectCreatedEvent`, `ObjectUpdatedEvent` and `ObjectDeletedEvent` (`CmsCacheInvalidationListener`). OpenRegister's `ObjectUpdatedEvent` offers `getNewObject()` and a nullable `getOldObject()`.
- `portalMessage` (`lib/Settings/portaliq_register.json:1024`) carries `subjectRef`, `organisation`, `subject`, `body`, `read`, `receivedAt`, the WMEBV fields and `taskUuid`. `src/portal/components/InboxPage.jsx:126-133` renders "View task" for a `taskUuid`.
- `portalAccount.notificationChannels` (`portaliq_register.json:801`) is `{"email": true}` by default; `PATCH /portal/api/identity/details` (`appinfo/routes.php:278`) takes `emailNotifications` through `PortalSelfServiceService::updateDetails()` (`lib/Service/Identity/PortalSelfServiceService.php:102`). No screen calls it with that field.
- `src/portal/App.jsx` has no address per page: the active page is React state (`activeKey`), and the only deep link is the inbox's task hand-off (`pendingTaskUuid`, `App.jsx:176-178`).

## D1. A change rule is declared by the case app

`notifications` keeps accepting plain strings. It also accepts a rule object:

```json
{"ruleKey": "case.updated", "collection": "mijnZaken", "on": {"field": "status", "operator": "changed"}, "titleField": "identifier"}
```

`collection` must be one of the same contribution's collections, using default subject scoping (no `scopeClaim`, no `via`). `field` must be one of that collection's projected `fields`. A malformed rule is dropped when the manifest is normalised, and logged.

The shape follows ADR-031's `updated` trigger with a `changed` condition on purpose. ADR-031's own engine cannot carry this: its recipients are Nextcloud users, and a portal resident is not one.

## D2. Portaliq notices the change

A new `PortalRecordChangeListener` on OpenRegister's `ObjectUpdatedEvent` looks for a change rule whose collection matches the object's register and schema. For each match:

1. Without an old object, it does nothing: a change it cannot see is not reported (fail closed, as ADR-031 does).
2. If `field` is equal in old and new, it does nothing.
3. The subject reference is the new object's value at the collection's `scopeField`. The account is found with `PortalAccountService::findBySubjectRef()` (`lib/Service/PortalAccountService.php:189`), which also gives the audience and organisation.
4. It writes a `portalMessage` for that subject with `recordLink` `{app, collection, id}`, the subject line "{title} has been updated" (Dutch: "{title} is bijgewerkt") where `{title}` is the value at `titleField`, else the collection label, and the body "Open it to see what changed." (Dutch: "Open het om te zien wat er is veranderd.").
5. It calls `NotificationDispatchService::dispatch()` with the rule's `ruleKey`.

The listener never throws into OpenRegister's save. Every failure is caught and logged, the same fail-safe posture `NotificationDispatchService` documents.

## D3. The resident is not told about their own change

Portaliq's own writes on a resident's behalf go through `PortalObjectWriter` and `PortalFileWriter`. A request-scoped `PortalWriteContext` is set while those writers call OpenRegister, and the listener skips any event raised inside it. The resident already saw the result on screen; `ContributionController`'s existing `status.changed` call keeps its current behaviour for apps that ask for it.

A background job of the case app, or a handler, writes outside that context, so their changes are reported.

## D4. A case app's message gets an e-mail

The same listener class also listens to `ObjectCreatedEvent`. For a created object whose register and schema match a `kind: inbox` collection of an app that declares `message.created`, it dispatches `message.created` for the subject at that collection's `scopeField`. Objects of portaliq's own `portalMessage` schema are skipped, because their writers already dispatch (`SubmissionReceiptService.php:279`, and D2's own write).

## D5. The e-mail says what kind of thing happened

`NotificationDispatchJob` picks its text by kind. `message.created` keeps today's text. A change rule gets "Something changed on your {label} in the portal of %1$s. Sign in to see it: %2$s", where `{label}` is the collection label. Neither carries case content, which keeps the privacy-minimal rule of the supplier-portal spec.

## D6. A stable address for a record

`PortalDeepLinkBuilder::forRecord(organisation, app, collection, id)` returns the portal address with the fragment `#open=<app>/<collection>/<id>`. The job uses it when the trigger carried a record, and `forOrganisation()` otherwise.

`App.jsx` reads and strips `#open=` on load, the way it reads `#token=`. Signed out, it keeps the target in `sessionStorage`, so it survives the sign-in round trip, and shows the login screen. Signed in, it finds the page holding a block for that app's collection, makes it active, and preselects the row. A `citizenCase` block opens the case screen. The row is then read through the scoped read as always: a link to someone else's record shows the screen's own "not yours" state.

`InboxPage.jsx` renders "Open" (Dutch: "Openen") for a message with `recordLink`, which does the same without a reload.

## D7. Preferences per kind and per channel

`portalAccount` gains `notificationPreferences`, a map from kind to `{email, push}` booleans. Two kinds: `case.updated` (every change rule) and `message.created`. A missing entry means on, as `notificationChannels` already does; `notificationChannels.email: false` still switches e-mail off for every kind.

Two new routes on `PortalAccountSelfController`, next to `updateDetails` (`appinfo/routes.php:278`): `GET /portal/api/identity/notification-preferences` returns the caller's own choices with `pushAvailable`, whether the account has a push subscription; `PATCH` on the same path writes only the two known kinds and the two known channels, for the caller's own account. There is no GET for the account's details today, and the lane change `identity-profile-page` owns that screen, so the preferences get their own pair rather than a field on it. `emailNotifications` on `PATCH /portal/api/identity/details` keeps working and keeps meaning `notificationChannels.email`.

`NotificationDispatchJob` checks the kind's e-mail choice before sending, and sends a push through `PushDeliveryService::deliver()` when the kind's push choice is on. Push attempts are logged as `portalNotification` rows with `channel` `push`, which the schema's `channel` enum (`portaliq_register.json:1298`, today `['email']`) gains.

The in-portal inbox message is not a preference. It is the record of what happened, and it is always written.

## D8. The settings screen

A "Notification settings" section (Dutch: "Meldingen instellen") at the top of `InboxPage.jsx`, collapsed by default, holds a small table: rows "Changes on your cases" and "New messages" (Dutch: "Wijzigingen in uw zaken", "Nieuwe berichten"), columns "E-mail" and "Push". The push column shows only when `pushAvailable` is true. Each cell is a checkbox with its own label. Saving shows "Your choices are saved." (Dutch: "Uw keuzes zijn opgeslagen.").

## Risks

- **Update volume.** Every OpenRegister update passes the listener's first check. That check is a lookup in the change rules, built once per request from the registry, keyed by register and schema. Only a match reads further.
- **A case app that rewrites its status often** would flood the inbox. The rule is the app's to declare; the docs page says to declare it only on a field a resident cares about.
- **The first change after this lands has no old object in some OpenRegister write paths.** Those changes are not reported, by design.

## What this change does not do

- It does not notify handlers.
- It does not change task delivery.
- It does not add a channel beyond e-mail and push.
