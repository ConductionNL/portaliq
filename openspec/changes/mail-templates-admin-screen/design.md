# Design: mail-templates-admin-screen

## Screen

Follows the Zuiddrecht board **PtMailsjablonen** ("portaliq: e-mailsjablonen en verzendlog", canvas `5NkFW28vZUUij43xzxHg5a`). A manifest page `MailTemplates` in `src/manifest.json`, breadcrumb Geavanceerd / Meldingen / E-mailsjablonen.

| Board element | What it does |
|---|---|
| Header actions "Verzendlog downloaden", "Testmail versturen" | CSV of the filtered log; a test mail of the selected template to the admin |
| Line "10 sjablonen, een per soort melding · afzender {name} <{address}>" | counts and the portal's sender |
| Table Soort melding, Onderwerpregel, Variabelen, Gewijzigd | one row per template key, stored or default |
| Editor: status Actief, Onderwerpregel, Tekst, variable chips, rule text | edits one `portalMailTemplate` |
| Opslaan, Voorbeeld, Standaardtekst terugzetten | save, render with sample values, delete the stored row |
| Verzonden e-mail: tabs Alles, Mislukt, In de wachtrij; columns Verstuurd, Aan, Sjabloon, Zaak, Status, Acties; "bewaard 90 dagen" | `portalMailLog` list; "Opnieuw versturen" on Mislukt |

## Data

New schemas in `lib/Settings/portaliq_register.json`:

- `portalMailTemplate` (schema.org `EmailMessage` template): `portal`, `templateKey`, `subject`, `body`, `active`, `updatedBy`. One row per portal and key.
- `portalMailLog` (schema.org `EmailMessage`): `portal`, `templateKey`, `recipientMasked`, `recipientHash`, `caseRef`, `status` (`queued`, `delivered`, `failed`), `failureReason`, `sentAt`, `retryOf`. Rows older than 90 days are removed by a daily background job.

The full address is not stored in the log; resend reads the address again from the account by `recipientHash`.

## Rendering

A new `MailTemplateRenderer` service owns the template keys, their default texts (moved from the mailer constants) and their variables. Every sender (`PortalIdentityMailer`, `NotificationDispatchJob`, `PortalTaskDeliveryJob`) asks the renderer for subject and body, then logs the send. Variables are replaced as plain text; the body is sent as text with links, never as HTML from the editor.

## Access

The screen and its writes are admin only (`#[AuthorizedAdminSetting]` on any new endpoint; the template rows are written through OpenRegister with admin RBAC). Resend is admin only and rate limited to 20 per minute.
