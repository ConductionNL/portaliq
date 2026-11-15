# Proposal: resident-sees-words-not-codes

## Why

Ruben walked the Woo journey on the Vue site (2026-10-02) and found the resident reading the system's own words. The left menu named apps ("Dossiq", "Pipelinq") instead of what a resident is there for. Notices and receipt e-mails were written twice, Dutch and English joined by " / ". Status cells, the publication page and "Mijn zaken" showed stored codes: `awaiting_customer`, `infocat014`, a case type uuid, the resident's own BSN after "Ingelogd als". The page editor named a block "markdown", and the "Gepubliceerd." notice did not reach AA contrast.

## What changes

- **Menu groups.** A contributed page MAY declare `group`, a short label in the reader's language. Pages with the same group, from any app, share one heading in the site's resident menu. Without it the page sits under its app's name, as before.
- **One language per notice.** The submission receipt, the notification e-mail and the delivered portal task speak the resident's language, chosen by `PortalNoticeLanguage` the same way change notices already are.
- **Words, not codes.** A collection MAY label the values of a detail field that is no column, through `fieldConfigs.<field>.valueLabels` (and `fieldConfigs.<field>.label`). The site shows the resident's display name, never the subject id. The publication page shows the category's name and only the fields a visitor needs. "Mijn zaken" shows the case type's name, never its uuid.
- **Editor polish.** The page editor names a block by its widget's name. The "Gepubliceerd." notice reaches AA contrast with theme variables only.

## Not changed

- What a resident may read. Labels and groups are presentation only.
- The React portal (`src/portal`), which is being retired.
- The apps' own declarations: the contributing apps (lane A of Woo round 3) add `group` and `valueLabels` to their manifests.
