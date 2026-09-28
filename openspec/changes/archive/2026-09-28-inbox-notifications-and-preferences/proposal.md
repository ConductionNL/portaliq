# Proposal: inbox-notifications-and-preferences

## Why

A resident is not told when their case moves. A handler changes the status, and nothing in the portal or in the resident's mail says so. A message from the case app lands in the portal inbox without an e-mail. The e-mail that does go out links to the portal's front page. And the resident cannot say which of these they want.

**Row `dem-tnd-notify-on-change`**, "Be notified in the portal when something changes on your case, with the case handler notified of your changes too." Origin `tender`, originUrl https://www.tenderned.nl/aankondigingen/overzicht/397282, note:

> TenderNed 397282 Gemeente Molenlanden, requirement 'Het portaal geeft bij een wijziging een notificatie aan zowel de klant als aan de behandelaar'; also 415380 Sudwest-Fryslan 'statuswijziging ... kenbaar in het klantportaal'

The matrix `built.note`: "Resident side: only a new task (ask, re-ask, reminder) or a message a case app writes into an inbox collection shows up as unread; nothing in portaliq observes a staff-made status or data change on a case, and the one status trigger (status.changed) fires on the resident's own update and is opted out by dossiq anyway. Handler side: portaliq raises PortalClientWriteEvent on every amendment, document and task answer, but no dossiq listener consumes it, so the handler is not told."

Competitor rated `yes`: xxllnc Zaken PIP, "backend/perl-api/lib/Zaaksysteem/Event/P5/ExternalMessageCreated.pm:57 e-mail to requestor; backend/perl-api/lib/Zaaksysteem/Controller/Plugins/PIP.pm:670 handler gets 'Kenmerk aangepast vanuit PIP'; backend/perl-api/lib/Zaaksysteem/Zaken/ComponentZaak.pm:2904 CaseFeedbackCreated [reached on e-mail and staff notifications; was unknown from docs]". No URL recorded.

**Row `cmp-inb-email-alert`**, "Get an e-mail telling you there is a new message in the portal." The matrix `built.note` says the e-mail is "Real and working for the two triggers the code actually fires" and adds: "A staff-authored manual portalMessage (see cmp-inb-reply) also does not go through SubmissionReceiptService, so it triggers no email either." Its `defectCandidate`: dossiq's supplier contribution "declares notifications ['tenderPublished','contractExpiring','invoiceDue'] but NotificationDispatchService::declaresRuleKey() is only ever called with 'message.created' or 'status.changed'". No demand row. Competitors rated `yes`:

- Open Inwoner Platform: "src/open_inwoner/accounts/notifications/messages/notify.py new-message mails via Celery (src/open_inwoner/accounts/notifications/tasks.py:34); user opt-in field messages_notifications (src/open_inwoner/accounts/forms.py:304) [reached on e-mail; behind SiteConfiguration.notifications_messages_enabled]". No URL recorded.
- xxllnc Zaken PIP: "backend/perl-api/lib/Zaaksysteem/Event/P5/ExternalMessageCreated.pm:57 new_int_pip_message_notification_template_id e-mail to requestor [reached on e-mail; needs the notification template configured]". No URL recorded.
- MijnOverheid: "https://mijn.overheid.nl/vragen/: e-mail notifications for new messages, configurable in Settings". URL: https://mijn.overheid.nl/vragen/
- Liferay DXP: "https://learn.liferay.com/w/dxp/low-code/notifications/configuring-personal-notifications 'Users can customize which notifications they receive by enabling email, or website notifications'; object notification actions send e-mail on entry events [...] [was unknown]". URL: https://learn.liferay.com/w/dxp/low-code/notifications/configuring-personal-notifications

**Row `cas-deeplink-notify`**, "Follow a link from a notification straight to the record it is about." The matrix `built.note`: "Deep links are confirmed wired for tasks (see tsk-notify); no evidence found of the same builder producing a link straight into a case screen, since the case screen itself is reached only through per-contribution nav, not a stable case URL." No demand row. Competitors rated `yes`:

- Open Inwoner Platform: "src/open_inwoner/openzaak/notifications.py:147 send_case_update_email puts case_link (reverse to case detail, :159) in the mail [reached on e-mail link to the case detail page; needs the OpenZaak notifications webhook (src/open_inwoner/openzaak/api/urls.py:8); was unknown from docs]". No URL recorded.
- NL Portal: "frontend/packages/authentication/src/utils/generate-redirect-uri.ts:9 original path kept as redirect_url through login; frontend/packages/app/src/constants/paths.ts:7 /zaken/zaak/:id and /berichten/bericht/:id routes [reached on any deep link, after DigiD login; the notification itself is sent by the case system, not by the portal; was unknown from docs]". No URL recorded.
- MijnOverheid: "https://www.logius.nl/onze-dienstverlening/interactie/voorzieningen/mijnoverheid/mijnoverheid-lopende-zaken: citizens can 'direct navigeren naar het dossier op uw website'; 'Elke keer als de status wijzigt, kan de burger daar een e-mailmelding over ontvangen'". URL: https://www.logius.nl/onze-dienstverlening/interactie/voorzieningen/mijnoverheid/mijnoverheid-lopende-zaken

**Row `cmp-inb-notif-prefs`**, "Choose which notifications you receive and through which channel." The matrix `built.evidence`: "grep for preference/notif-setting/channel-choice in src/portal: 0 hits; the only channel is a hardcoded email send in NotificationDispatchJob.php, with no per-subject opt-out or channel choice". Since then portaliq#685 (open change `notification-preferences-per-role`) added an e-mail opt-out on `PATCH /portal/api/identity/details`, with no control on any screen. No demand row. Competitors rated `yes`:

- MijnOverheid: "https://mijn.overheid.nl/vragen/: configurable e-mail notifications, app notifications and reminders for unopened messages". URL: https://mijn.overheid.nl/vragen/
- Liferay DXP: "https://learn.liferay.com/w/dxp/low-code/notifications/configuring-personal-notifications 'Users can customize which notifications they receive by enabling email, or website notifications for the desired applications.' [was unknown]". URL: https://learn.liferay.com/w/dxp/low-code/notifications/configuring-personal-notifications

## What changes

- **A case app declares which change is worth telling the resident.** A contribution's `notifications` list may hold a rule with a collection and a field, for example "the `status` of `mijnZaken` changed". The plain rule keys keep working.
- **Portaliq watches for that change.** When OpenRegister reports the declared field changed on a record, and the change was not the resident's own, portaliq puts a message in the resident's portal inbox and sends the out-of-band nudge.
- **Every inbox message can get an e-mail.** A message a case app writes into its inbox collection now triggers the same nudge as portaliq's own receipts, when the app declares `message.created`.
- **The link goes to the record.** A notification about a case carries a link that opens that case after sign-in. The inbox message has an "Open" button that does the same.
- **The resident chooses.** A "Notification settings" section on the inbox page lets them switch e-mail and push on or off, separately for case updates and for new messages.

## Rows this closes

| Matrix | Row id | Row name | Own rating | What is missing |
|---|---|---|---|---|
| portaliq | `dem-tnd-notify-on-change` | Be notified in the portal when something changes on your case, with the case handler notified of your changes too. | partial | Portaliq noticing a staff change and telling the resident. The handler side is dossiq's, named below. |
| portaliq | `cmp-inb-email-alert` | Get an e-mail telling you there is a new message in the portal. | partial | An e-mail for messages written by a case app, not only for portaliq's own receipts. |
| portaliq | `cas-deeplink-notify` | Follow a link from a notification straight to the record it is about. | partial | A stable address for a record in the portal, kept through sign-in. |
| portaliq | `cmp-inb-notif-prefs` | Choose which notifications you receive and through which channel. | no | A screen, per kind and per channel. |

## Existing work it builds on

- `openspec/changes/archive/2026-07-24-portal-notifications-dispatch` and `openspec/specs/supplier-portal/spec.md`, requirements "Manifest notification rule keys drive an out-of-band email", "Dispatch is decoupled from the request path", "Every dispatch attempt is logged" and "Repeated failure flags an alternative-contact fallback". All four stay.
- `openspec/changes/notification-preferences-per-role` (open, portaliq#685): `portalAccount.notificationChannels` and the opt-out gate in `NotificationDispatchJob`. This change adds the screen and the per-kind choice on top.
- `openspec/changes/push-notifications-quiet-hours` (open): `PushDeliveryService` and `QuietHoursPolicy`, reused for the push channel.
- `openspec/changes/archive/2026-07-23-portal-inbox-v2` and `openspec/changes/portal-task-delivery` (open): the unified inbox and the task deep link this change generalises.

## Out of scope

- Task reminders. `PortalTaskDeliveryJob` keeps its own delivery; its preferences can follow later.
- Notifications for collections scoped through `scopeClaim` or `via`. Portaliq cannot turn such a record back into one resident without a second lookup it does not have. Default subject scoping only.
- The government message box as a channel. The change `inbox-berichtenbox-channel` adds it as a third channel.
- Staff notifications. See sibling halves.

## Sibling halves

- **ConductionNL/dossiq owes** a listener on portaliq's `PortalClientWriteEvent` that tells the handler when a resident amends an answer, adds a document or answers a task. The matrix records "no dossiq listener consumes it". Dossiq also owes the change rule on its `mijnZaken` collection (`status` changed) and `message.created` on its citizen contribution, which today declares `'notifications' => []`.
