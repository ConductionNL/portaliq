---
kind: code
---

# Proposal: mail-templates-admin-screen

## Why

A municipality wants its status mail to say "Beste Sanne" and to sign off with the department's name. Today that is a code change: every subject and body portaliq sends is a constant in `lib/Service/Identity/PortalIdentityMailer.php` (`:87`-`:118`) and in the notification job. Nobody can see which mails went out either, or send a failed one again.

Open Inwoner edits its mail texts in the admin with the `mail_editor` app (`src/open_inwoner/conf/base.py:372`). The Zuiddrecht board **PtMailsjablonen** draws the screen: a template per kind of notice with variables, a test mail, and a send log with resend.

## What changes

- **A template per kind of mail.** `portalMailTemplate` holds subject and body per template key, per portal. The code keeps its texts as defaults; a stored template overrides the default for that portal.
- **The admin screen.** Under Geavanceerd, Meldingen, E-mailsjablonen: the table Soort melding, Onderwerpregel, Variabelen, Gewijzigd; an editor with the variables as chips that insert at the cursor; Opslaan, Voorbeeld, Standaardtekst terugzetten; and "Testmail versturen" to the admin's own address.
- **Only known variables.** Each template key declares its variables (`{voornaam}`, `{zaaknummer}`, `{link}`). Saving a text with an unknown variable is refused.
- **No content in a mail, still.** The editor shows the rule "De e-mail noemt alleen wat voor wijziging het is en linkt naar de zaak." No variable carries a field value of a record (REQ-NAP-006).
- **The send log.** Every mail portaliq sends is logged with time, masked recipient, template, case and status, kept 90 days. A failed mail offers "Opnieuw versturen". "Verzendlog downloaden" exports the filtered log as CSV.

## Rows covered

- `ops-mail-templates-ui` (decisions 101 and 102), screen PtMailsjablonen.

## Out of scope

- The digest schedules the board draws under Samenvattingen. Portaliq sends no digests today; that is a separate change.
- Reprocessing incoming notices from a case system ("Binnengekomen meldingen van zaaksystemen"). Those belong to the integration that receives them.
