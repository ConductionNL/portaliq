# Design: identity-profile-page

Read at portaliq `development` `eeda3fa`.

## Where it sits today

- `lib/Controller/PortalAccountSelfController.php`: `updateDetails()` (:92,
  `PATCH /portal/api/identity/details`), `confirmEmail()` (:125,
  `POST /portal/api/identity/email/confirm`), `removeAccount()` (:144,
  `POST /portal/api/identity/remove`). All `#[PublicPage]`, subject from the
  bearer, `AnonRateLimit`.
- `lib/Service/Identity/PortalSelfServiceService.php`: `updateDetails()`
  (:102) writes `pendingEmail`, `pendingEmailTokenHash`,
  `pendingEmailExpiresAt` and returns the plain `confirmationToken`;
  `confirmEmail()` (:179); `removeAccount()` (:263) blanks the identity,
  e-mail, name and claims and sets `status: removed`.
- `portalAccount` (`lib/Settings/portaliq_register.json`): one `email`,
  `pendingEmail*`, `notificationChannels`, `needsAlternativeContact`, no phone
  and no channel preference.
- `lib/BackgroundJob/NotificationDispatchJob.php` reads `portalAccount.email`
  and sets `needsAlternativeContact` after repeated failures (:491-516).
- Mail: portaliq sends through `OCP\Mail\IMailer` only in
  `NotificationDispatchJob`, `PortalTaskDeliveryJob` and
  `TrafficReportDelivery`. No identity mail exists.
- `GET /portal/api/session` (`lib/Controller/SessionController.php`, index,
  :179-187) answers `subjectRef`, `audience`, `organisation`, `trust`.
- The SPA shell `src/portal/App.jsx` builds its nav in `buildNav()` (:48-76)
  with fixed entries `special: 'tasks'` and `special: 'inbox'`.

## D1. One identity mailer

`lib/Service/Identity/PortalIdentityMailer.php` sends the identity mails
through `IMailer`, in the portal's language, with the portal's name from
`PortalRuntimeConfigResolver`. It takes a template key and the plain secret,
builds the link, sends, and forgets the secret. This change adds the first
template, `email-confirmation`; `identity-ways-in-screens` and
`identity-staff-account-screens` add theirs. Whichever change is built first
creates the class.

The confirmation link is `/portal/#confirm-email=<secret>`. The SPA consumes
the fragment once on mount, the same way it consumes the OIDC callback
fragment (`consumeOidcCallbackFragment()` in `src/portal/lib/portalApi.js:716`,
called from `src/portal/App.jsx:96-99`), and
posts it to `confirmEmail()`. A fragment never reaches a server access log.

`updateDetails()` in the controller calls the mailer when a token came back,
instead of dropping it.

## D2. Several addresses, one of them is `email`

`portalAccount` gains `contactAddresses`: an array of
`{ kind: 'email' | 'phone', value, confirmed, preferred }`. Rules, enforced in
`PortalSelfServiceService`:

- An e-mail address is added unconfirmed and gets the confirmation mail.
- At most one preferred address per kind. Marking a confirmed e-mail preferred
  copies it into `email`. An unconfirmed one cannot be preferred.
- Removing the preferred e-mail is refused while another confirmed one exists
  and none is marked; you pick the next one first.
- Phone numbers are stored as given, normalised to E.164 where they parse.

`email` stays the one field dispatch reads, so `NotificationDispatchJob` does
not change. The existing single-address PATCH keeps working: it adds the
address to `contactAddresses` and marks it preferred once confirmed.

## D3. The contact channel is a preference and an event

`portalAccount.contactChannel`, enum `portal`, `email`, `phone`, `post`,
default `portal`. Setting it raises
`OCA\Portaliq\Event\PortalContactDetailsChangedEvent` (subjectRef, organisation,
channel, and whether a preferred e-mail and phone exist), dispatched through
`IEventDispatcher` (ADR-041). Portaliq sends no letter and makes no call. The
event is the contract.

## D4. The prompt is decided by the server

`SessionController::index()` adds `contactPrompt: true` when the account has no
confirmed e-mail or `needsAlternativeContact` is true. The SPA shows a
dismissible notice above the page ("Add an e-mail address so we can tell you
when something changes.") with a link to "My account". Dismissing it lasts for
the session; the next sign-in asks again while the condition holds.

## D5. A read endpoint for your own account

`GET /portal/api/identity/details` on `PortalAccountSelfController` answers
`displayName`, `contactAddresses`, `pendingEmail` (masked), `contactChannel`
and `notificationChannels` of the caller's own account. It answers nothing
else: no `identityRef`, no `claims`.

## D6. Removal keeps the cases and says so

The page shows what removal does before it happens: "Your portal account is
removed. Your cases stay with the organisation." Confirming calls the existing
`removeAccount()`, then the SPA calls `logout()` and returns to the sign-in
screen.

## Risks

- Several addresses mean several confirmation mails. `AnonRateLimit` on the
  PATCH (20 per minute) is per client; a per-account cap of five pending
  confirmations prevents a mailbomb from one session.
- The removal is final. The confirmation step names that.

## What it deliberately does not do

- It does not change `NotificationDispatchJob`.
- It adds no admin screen; staff see the fields on the generic account detail.
