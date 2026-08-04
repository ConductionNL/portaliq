# Proposal: portal-notifications-dispatch (consume the contract's notification rule keys)

## Why

The contribution contract already lets a fleet app **declare** a `notifications`
list of rule keys in its manifest — and **nothing consumes them**. Agent A's
inventory records it plainly: *"notifications[ruleKey] (DECLARED, NOT CONSUMED)"*
and *"notifications-to-externals MISSING — contract rule keys consumed by
NOTHING; no mailer/push code."* A rule key sits inert in every contribution.

This is the #3 citizen wish and a WMEBV duty at once:

- **Proactive notification** is a top-tier citizen wish (*"don't make me poll"*).
  The Nationale ombudsman found **17% of Berichtenbox accounts had no
  notification email set**, so a message placed with no out-of-band nudge is a
  message not read (ombudsman 2017/098).
- **WMEBV notificatieplicht + fallback (~Awb 2:11)** requires the bestuursorgaan
  to notify the sender that a message is waiting, and — if notification
  **repeatedly fails** — to fall back to an alternative contact channel.
- Demand is quantified: `notificatie` appears in **539 tender requirements across
  150 tenders**; `berichtenbox` in **161 / 74**.

Nextcloud's own `notify_push` is not usable here — the portal SPA is not a
Nextcloud client (agent D). So Portaliq must own an out-of-band channel. The
minimal, privacy-safe channel is email via `OCP\Mail\IMailer`: *"You have a new
message in the portal of &lt;org&gt;"* plus a deep link — **no case content in
the email**.

## What Changes

- A `NotificationDispatchService` consumes the manifest `notifications` rule
  keys. When a `portalMessage` is created for a subject, OR a status-transition
  update succeeds for a subject, AND the contributing app's manifest declares a
  matching rule key, the service dispatches a **privacy-minimal** email to the
  subject's `portalAccount.email`: a subject line and body naming only the
  organisation and a deep link into the portal — never the message body, case
  data, or any content.
- NL and EN email templates at **B1 language level**.
- Every attempt is logged in a new `portalNotification` record (`accountRef`,
  `ruleKey`, `channel`, `status` sent/failed, `attempts`, `lastAttemptAt`).
- After **N consecutive failures** the account is flagged
  `needs-alternative-contact` (the WMEBV notificatieplicht fallback signal), and
  the failure count is surfaced (count-only) via the existing metrics endpoint
  (`MetricsController`).
- Dispatch is **decoupled from the request path**: a `portalMessage` create /
  successful transition enqueues a background job (`OCP\BackgroundJob\IJobList`);
  the email send happens in the job, not inline, so a slow or failing SMTP server
  never slows or fails a subject's request.

## Out of scope

- SMS / push channels (the `channel` field is future-proofed but only `email` is
  implemented).
- The 2:10 aard/rechtsgevolg/termijn notification-content duties — `portal-inbox-v2`.
- The receipt-of-submission itself — that is `wmebv-submission-receipts`; this
  change notifies about inbox messages and transitions.

## Dependencies

- Builds on `wmebv-submission-receipts` (the receipt `portalMessage` is a primary
  trigger) and `portal-status-transitions` (the successful-transition trigger).
  Additive; consumes an already-declared, previously-inert contract field.
