# Design: help-texts-and-form-help

## Form help

Follows the Zuiddrecht board **FormulierHulp** ("Woo-verzoek indienen, hulp nodig", canvas `5NkFW28vZUUij43xzxHg5a`):

| Board element | Source |
|---|---|
| Link "Hulp nodig?" right of the form title | shown when the portal or the form has help details |
| Dialog title "Hulp nodig bij dit formulier?", image, intro "Komt u er niet uit ...? Wij helpen u graag. Uw antwoorden blijven staan als u dit venster sluit." | `help.intro`, `help.image` |
| Telefoon "[telefoonnummer]", "Noem dat u hulp nodig heeft bij het Woo-verzoek." | `help.phone`, `help.phoneNote` (may name the form with `{formulier}`) |
| Openingstijden | `help.hours` |
| Balie "Stadskantoor, Lindelaan 1. Maak eerst een afspraak, dan vullen wij het formulier samen met u in." | `help.desk` |
| "Vraag per e-mail", "Sluiten" | `mailto:` with `help.email` and the form title as subject; close |

The dialog lives in `src/site/modals/FormHelpModal.vue` (ADR-004). It does not touch the form state; focus returns to "Hulp nodig?" on close.

## Page and section help (no board yet)

Under the page heading a disclosure "Hulp bij deze pagina" (NL DS Accordion with one item, closed by default) shows the help text. It renders only when a text exists. For a CMS page the text is `page.helpText`. For Mijn omgeving the texts are `portal.sectionHelp` keyed by `overview`, `cases`, `tasks`, `messages`, `contacts`. This is the design the builder follows; it is on `for-design/portaliq-missing-boards.md` for a board.

## Data

- `portal.help` (schema.org `ContactPoint`): `intro`, `image`, `phone`, `phoneNote`, `hours`, `desk`, `email`.
- `form.help`: the same shape; each key set on the form overrides the portal's.
- `page.helpText` (markdown, sanitised) and `portal.sectionHelp` (object of markdown strings).

Portal, form and page schema versions bump; all new keys are optional, so existing rows stay valid.
