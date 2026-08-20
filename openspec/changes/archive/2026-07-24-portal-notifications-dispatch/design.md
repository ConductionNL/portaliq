# Design: portal-notifications-dispatch

## Why a background job, not inline

The trigger points — a `portalMessage` create (from `wmebv-submission-receipts`
or any app), and a successful status transition — sit on the request path of a
subject action or a server-to-server side-effect. Sending email inline would
couple the subject's latency and success to the SMTP server: a slow relay
delays the response, a failing relay could bubble into the request. Neither is
acceptable for a compliance notification.

So dispatch is decoupled:

1. The trigger enqueues a job via `OCP\BackgroundJob\IJobList` with a minimal
   payload (`accountRef`, `ruleKey`, deep-link target) — no message content.
2. `NotificationDispatchJob::run()` resolves the `portalAccount`, checks the
   `ruleKey` is declared by the contributing app's manifest, renders the
   privacy-minimal template, and sends via `OCP\Mail\IMailer`.
3. The job writes / updates the `portalNotification` record with the outcome.

The enqueue is cheap and cannot fail the request; the send is retried by the job
runner's own scheduling, and the `attempts` counter tracks consecutive failures.

## Privacy-minimal payload

The email carries only: the organisation display name, a generic subject line
(*"Er staat een nieuw bericht voor u klaar in het portaal van &lt;org&gt;"* /
*"You have a new message in the portal of &lt;org&gt;"*), and a deep link into
the portal (`/portal?org=<slug>` + the inbox route). It MUST NOT carry the
message subject, body, case identifiers, or any personal data beyond the
recipient address — an email inbox is a lower-trust surface than the
authenticated portal, and WMEBV notification is a *nudge to log in*, not delivery
of content.

The deep link routes through the SPA's existing deep-linking, landing the subject
at the authenticated inbox after login — content is only ever shown behind the
portal auth edge.

## Failure fallback (WMEBV notificatieplicht)

`portalNotification.attempts` counts consecutive failures per account+ruleKey.
After a configurable threshold `N` (default small, e.g. 3), the job flags the
`portalAccount` `needs-alternative-contact`. This is the WMEBV fallback signal:
the operator (or a downstream process) must reach the subject by another channel.
The count is surfaced count-only via `MetricsController` (e.g.
`portaliq_notifications_failed_total`, `portaliq_accounts_needs_alt_contact`) so
an operator can see the fallback backlog — never the recipients themselves.

## Matching a rule key

Dispatch only fires when the contributing app's manifest `notifications` list
declares a rule key matching the trigger (e.g. `message.created`,
`status.changed`). An app that declares no matching key gets no email — the
feature is opt-in per contribution, honouring the app's own notification policy
and avoiding double-notifying when an app already runs OR's own notification
engine. Unknown / malformed rule keys are ignored fail-closed.

## Relationship to OpenRegister notifications

OR ships a notification engine (ADR-031 dialect) that can email on object events.
That path emails on the *server/tenant* side via OR's own config; this change is
the *portal-subject* side, keyed off the portal manifest and sending to the
external `portalAccount.email`, not an NC user. They are complementary; this
change does not touch the ADR-031 dialect and must not emit the legacy
notification dialect into `portaliq_register.json`.
