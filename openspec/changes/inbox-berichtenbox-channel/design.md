# Design: inbox-berichtenbox-channel

Read at portaliq development `eeda3fa` and integriq development on 2026-09-27. In portaliq's code this channel is `messageBox`; the government product's name appears only in integriq's adapter and in the label an organisation configures.

## What is there today

- `lib/BackgroundJob/NotificationDispatchJob.php:91` `CHANNEL_EMAIL = 'email'`, "The only channel implemented". `portalNotification` (`lib/Settings/portaliq_register.json:1298`) requires `accountRef`, `ruleKey`, `channel`, `status`, `attempts` and `lastAttemptAt`; `channel` is the enum `['email']`, `status` the enum `['sent', 'failed']`.
- `portalAccount.identityRef` is described as "Pseudonymous identity reference from the IdP [...] Not a raw BSN." Portaliq holds no identity a government message box can be addressed with.
- The timeline pattern (`lib/Service/PortalTimelineReader.php:71`): a collection names a method on the case app's own portal provider, and portaliq calls it server-side after proving the subject owns the record.
- Integriq development: `DigitalPostSendRequestedEvent(sourceApp, sourceId, recipient, subject, body, attachments, requestedBy, correlationId)`, where `recipient` is "The recipient identity, for example a BSN" and `sourceId` is the slug of an integriq digital post source. `DigitalPostSendRequestedListener` always answers: a message id or a refusal (`setRefusal()`). Its docblock asks the consumer to "guard the dispatch with class_exists() and treat an unhandled event as a refused send, never as a delivered one". `DigitalPostDeliveredEvent` carries `getMessageId()`, `getStatus()`, `getPreviousStatus()`, `getRequestedBy()`, `isSimulated()` and `getLastError()`.

## D1. The recipient comes from the case app, and passes through

A message box is addressed by a citizen service number. Portaliq holds none, on purpose. The case app that wrote the message does hold it, for the case it is about.

So an inbox collection may declare `messageBox: {recipientProvider: "<method>"}`. The method, on the case app's portal provider, takes the message id and returns the recipient identity, or null when this message should not go to the message box. Portaliq validates the name like a timeline provider, calls it server-side, passes the value into the event, and drops it. The value is never logged, never written to any portaliq object and never sent to the browser.

The alternative, the case app dispatching integriq's event itself, gives the resident no single place that knows a message went two ways, and no way to switch it off. The lane assigned the message box abstraction to portaliq; this is the smallest shape that keeps it there without portaliq holding the identity.

## D2. The organisation turns the channel on

The organisation's presentation override gains `messageBox: {sourceId, label}`: the integriq digital post source slug, and the label residents read (for example "MijnOverheid Berichtenbox"). Without it, the channel does not exist for that organisation: no preference is shown and nothing is sent.

## D3. When a message goes

The listener from `inbox-notifications-and-preferences` (D4 there) already sees each new record in a `kind: inbox` collection. For a collection that declares `messageBox`, and an organisation with the channel on, and a resident whose preference for it is not off, it enqueues a `NotificationDispatchJob` with `channel` `messageBox` and the message's record reference.

The job calls the recipient method, and when it answers with an identity:

1. It checks `class_exists` on integriq's event. Absent means refused.
2. It dispatches `DigitalPostSendRequestedEvent` with `sourceApp` `portaliq`, the organisation's `sourceId`, the recipient, the message's `subject` and `body`, the message's attachment references, and `requestedBy` `portaliq`.
3. An event nobody handled is a refusal. A refusal is logged as `failed` with the refusal code; a message id is logged as `sent`.

The `portalNotification` row stores integriq's message id in a new `externalMessageId` property and the message's record reference in `recordLink`. The `channel` enum gains `messageBox`, the `status` enum gains `delivered`, `read` and `simulated`.

## D4. Integriq's status reaches the inbox

A `PortalDigitalPostDeliveredListener` on `DigitalPostDeliveredEvent` ignores events whose `requestedBy` is not `portaliq`. For the rest it finds the `portalNotification` row by `externalMessageId` and records the new status. A simulated event records `simulated`, never `delivered`: integriq's own change was written after a mock reported success for letters that never left the instance.

`PortalInboxReader` adds `_deliveries` to a message row: the channels on which a `portalNotification` row for that record reached `delivered` or `read`. `InboxPage.jsx` shows "Also sent to {label}." (Dutch: "Ook verstuurd naar {label}.") for the message box. A pending, failed or simulated send shows nothing to the resident; the administrator sees it in the notification log.

## D5. The resident's choice

The settings section from `inbox-notifications-and-preferences` gains one row when the organisation offers the channel: "Also send letters to {label}" (Dutch: "Brieven ook naar {label} sturen"), on by default. It is stored as `notificationPreferences.messageBox: {enabled}` on the account.

## Risks

- **Two senders for one letter.** A case app that already sends a letter to the message box itself, as dossiq's compose dialog does, and also returns a recipient for the same message, sends it twice. The contract docs say to return null for a message the app delivers itself.
- **Everything is simulated until integriq's live leg lands.** Until then the resident sees no delivery line, which is honest.
- **Attachment shape.** Integriq takes attachment references as arrays; dossiq's message attachments are document ids. The reference portaliq passes is whatever the message row carries; integriq resolves it or refuses.

## What this change does not do

- It does not store or show a citizen service number anywhere.
- It does not talk to Logius. Integriq does.
- It does not receive post.

## Notes from the build (2026-09-29)

- **Integriq reports before portaliq writes.** `DigitalPostService::handleSendRequest` dispatches the first `DigitalPostDeliveredEvent` inside the send, before it sets the message id, so the listener hears about a row that does not exist yet. The listener keeps that status in `MessageBoxStatus`, and the sender writes it onto the row it creates. Without this, every simulated letter would have been logged as `sent`.
- **The refusal code has its own property.** `portalNotification.refusalCode` holds `not_installed`, `unhandled`, `dispatch_failed` or integriq's own code. Integriq's refusal reason is not stored: it may quote the recipient.
- **The job reads the message, the queue does not carry it.** A job argument is capped in size, so the job carries the message's reference and its collection's register, schema and scope field, and reads the message scoped to the resident when it runs.
- **The channel does not depend on `message.created`.** An app that declares `messageBox` on an inbox collection gets its letters sent even without the e-mail rule.
- **A choice, not a kind.** `notificationPreferences.messageBox` is written only once the resident makes the choice; before that it reads as on.
